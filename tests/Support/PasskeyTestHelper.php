<?php

declare(strict_types=1);

namespace App\Tests\Support;

use CBOR\ByteStringObject;
use CBOR\Encoder;
use JsonException;
use OpenSSLAsymmetricKey;
use ParagonIE\ConstantTime\Base64UrlSafe;
use RuntimeException;

/**
 * Builds real, cryptographically valid WebAuthn ceremonies for tests.
 *
 * Nothing here is mocked: the helper generates a P-256 keypair, builds a proper
 * COSE public key, signs a correct `authenticatorData` with OpenSSL, and
 * assembles the CBOR `attestationObject` a real authenticator would send. A
 * passing test therefore proves the ceremony works, rather than proving that our
 * code agrees with our own stubs.
 *
 * Two rules learned the hard way, both encoded below:
 *  - **The counter is controlled explicitly.** A stale counter raises
 *    `CounterException`, which can mask the real reason a verification failed and
 *    turn a negative test into a false pass.
 *  - **Options are serialised by the library**, never `json_encode()`d — the
 *    challenge is raw binary and `json_encode()` rejects it outright.
 */
final class PasskeyTestHelper
{
    /** The `none` attestation format requires an all-zero AAGUID. */
    private const string ZERO_AAGUID = '00000000000000000000000000000000';

    private readonly OpenSSLAsymmetricKey $key;

    /** @var array{x: string, y: string} */
    private array $coordinates;

    private int $counter = 0;

    public function __construct()
    {
        $key = openssl_pkey_new([
            'private_key_type' => \OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        if (!$key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('Unable to generate a P-256 keypair for tests.');
        }

        $details = openssl_pkey_get_details($key);
        if (!\is_array($details) || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('Unable to read the generated P-256 keypair.');
        }

        $this->key = $key;
        $this->coordinates = [
            'x' => $details['ec']['x'],
            'y' => $details['ec']['y'],
        ];
        $this->counter = 0;
    }

    public function credentialId(): string
    {
        return random_bytes(16);
    }

    public function counter(): int
    {
        return $this->counter;
    }

    /**
     * COSE-encoded public key, as carried in the attested credential data.
     *
     * Keys are the standard COSE labels: 1 = EC2, 3 = ES256, -1 = P-256,
     * -2 = x, -3 = y. Binary coordinates must be byte strings, so they bypass
     * the encoder's UTF-8 detection.
     */
    public function cosePublicKey(): string
    {
        return (new Encoder())->encode([
            1 => 2,
            3 => -7,
            -1 => 1,
            -2 => ByteStringObject::create($this->coordinates['x']),
            -3 => ByteStringObject::create($this->coordinates['y']),
        ]);
    }

    /**
     * Authenticator data for a registration: rpIdHash, flags (UP|AT|UV), a
     * counter, then the attested credential data.
     */
    public function attestationAuthenticatorData(string $rpId, string $credentialId, bool $userVerified = true): string
    {
        $flags = 0x01 | 0x40; /* UP | AT */
        if ($userVerified) {
            $flags |= 0x04; /* UV */
        }

        return hash('sha256', $rpId, true)
            .\chr($flags)
            .pack('N', ++$this->counter)
            .hex2bin(self::ZERO_AAGUID)
            .pack('n', \strlen($credentialId))
            .$credentialId
            .$this->cosePublicKey();
    }

    /**
     * Authenticator data for an assertion: rpIdHash, flags (UP|UV), a counter.
     * The attested credential data lives in the stored record, not here.
     */
    public function assertionAuthenticatorData(string $rpId, int $counter, bool $userVerified = true): string
    {
        $flags = 0x01; /* UP */
        if ($userVerified) {
            $flags |= 0x04; /* UV */
        }

        return hash('sha256', $rpId, true).\chr($flags).pack('N', $counter);
    }

    /**
     * clientDataJSON with the challenge encoded exactly as a browser encodes it.
     *
     * @throws JsonException
     */
    public function clientData(string $type, string $challenge, string $origin): string
    {
        return json_encode([
            'type' => $type,
            'challenge' => Base64UrlSafe::encodeUnpadded($challenge),
            'origin' => $origin,
            'crossOrigin' => false,
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * The CBOR attestation object a browser sends, in the `none` format.
     */
    public function attestationObject(string $authData): string
    {
        return (new Encoder())->encode([
            'fmt' => 'none',
            'attStmt' => [],
            'authData' => ByteStringObject::create($authData),
        ]);
    }

    /**
     * Sign `authData || sha256(clientDataJSON)` with the generated key.
     *
     * WebAuthn wants the raw 64-byte (r||s) form, but OpenSSL emits DER, so the
     * result is converted. Getting this wrong produces a confusing "invalid
     * signature" that looks like a logic bug rather than an encoding one.
     */
    public function signature(string $authData, string $clientDataJson): string
    {
        $data = $authData.hash('sha256', $clientDataJson, true);

        $der = '';
        openssl_sign($data, $der, $this->key, \OPENSSL_ALGO_SHA256);

        return self::derToRaw($der);
    }

    /**
     * A ready-to-submit registration `credential`, matching what
     * `navigator.credentials.create()` produces.
     *
     * @return array<string,mixed>
     *
     * @throws JsonException
     */
    public function registrationCredential(
        string $rpId,
        string $challenge,
        string $origin,
        ?string $credentialId = null,
    ): array {
        $credentialId ??= $this->credentialId();
        $clientDataJson = $this->clientData('webauthn.create', $challenge, $origin);
        $authData = $this->attestationAuthenticatorData($rpId, $credentialId);

        return [
            'id' => Base64UrlSafe::encodeUnpadded($credentialId),
            'rawId' => Base64UrlSafe::encodeUnpadded($credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientDataJson),
                'attestationObject' => Base64UrlSafe::encodeUnpadded($this->attestationObject($authData)),
                'transports' => ['internal'],
            ],
        ];
    }

    /**
     * A ready-to-submit assertion `credential`, matching what
     * `navigator.credentials.get()` produces.
     *
     * @return array<string,mixed>
     *
     * @throws JsonException
     */
    public function assertionCredential(
        string $rpId,
        string $challenge,
        string $origin,
        string $credentialId,
        int $counter,
        string $userHandle,
    ): array {
        $clientDataJson = $this->clientData('webauthn.get', $challenge, $origin);
        $authData = $this->assertionAuthenticatorData($rpId, $counter);

        return [
            'id' => Base64UrlSafe::encodeUnpadded($credentialId),
            'rawId' => Base64UrlSafe::encodeUnpadded($credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientDataJson),
                'authenticatorData' => Base64UrlSafe::encodeUnpadded($authData),
                'signature' => Base64UrlSafe::encodeUnpadded($this->signature($authData, $clientDataJson)),
                'userHandle' => Base64UrlSafe::encodeUnpadded($userHandle),
            ],
        ];
    }

    /**
     * Convert an OpenSSL DER signature to the fixed-length raw form WebAuthn
     * mandates: 64 bytes for P-256, big-endian r||s.
     *
     * DER is `30 <len> 02 <lenR> R 02 <lenS> S`. Both integers are
     * variable-length and may carry a leading zero byte, so each coordinate is
     * right-aligned into exactly 32 bytes. The library rejects anything that is
     * not exactly 64 bytes, and a malformed result looks like "invalid
     * signature" rather than an encoding bug — hence the explicit round-trip
     * check in the tests.
     *
     * @throws RuntimeException when the input is not a well-formed ECDSA-Sig-Value
     */
    private static function derToRaw(string $der): string
    {
        $length = \strlen($der);
        /* short-form SEQUENCE header: tag + one length byte */
        if ($length < 8 || 0x30 !== \ord($der[0])) {
            throw new RuntimeException('Expected a DER SEQUENCE.');
        }

        $offset = 2;

        if (0x02 !== \ord($der[$offset])) {
            throw new RuntimeException('Expected a DER INTEGER for r.');
        }
        $lengthR = \ord($der[$offset + 1]);
        $r = substr($der, $offset + 2, $lengthR);
        $offset += 2 + $lengthR;

        if (0x02 !== \ord($der[$offset])) {
            throw new RuntimeException('Expected a DER INTEGER for s.');
        }
        $lengthS = \ord($der[$offset + 1]);
        $s = substr($der, $offset + 2, $lengthS);

        return str_pad(substr($r, -32), 32, "\x00", \STR_PAD_LEFT)
            .str_pad(substr($s, -32), 32, "\x00", \STR_PAD_LEFT);
    }
}
