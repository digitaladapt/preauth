<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Serializer\SerializerInterface;
use Throwable;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\Exception\InvalidDataException;

/**
 * Builds the WebAuthn collaborators, and is the one place that knows how they
 * are wired.
 *
 * Everything that touches webauthn-lib types goes through here, so a library
 * major version that renames or moves those types is a single-file change
 * instead of a hunt through the codebase.
 *
 * **Attestation is deliberately `none`.** The alternatives were measured and
 * rejected: attestation conveyance is only a preference a client may ignore,
 * and the FIDO metadata service is bypassed both by the zero AAGUID that
 * privacy-preserving passkeys already send and by self attestation — while
 * still refusing legitimate authenticators that postdate its cached blob. See
 * `docs/passkey-auth-subdomain-plan.md` §2.3 for the evidence, and `SECURITY.md`
 * for the conditions that would justify revisiting it.
 */
final readonly class PasskeyCeremonyFactory
{
    private AttestationStatementSupportManager $attestationStatementSupportManager;

    public function __construct()
    {
        $manager = AttestationStatementSupportManager::create();
        $manager->add(NoneAttestationStatementSupport::create());
        $this->attestationStatementSupportManager = $manager;
    }

    /**
     * The serializer for every WebAuthn value: credential records, ceremony
     * options, and the client's response.
     *
     * This must be used instead of `json_encode()`. Options carry raw binary
     * (the challenge) which `json_encode()` rejects outright, and
     * `CredentialRecord` is not `JsonSerializable` at all — the serializer
     * base64url-encodes those fields and is required for a correct round-trip.
     */
    public function serializer(): SerializerInterface
    {
        return (new WebauthnSerializerFactory($this->attestationStatementSupportManager))->create();
    }

    public function attestationStatementSupportManager(): AttestationStatementSupportManager
    {
        return $this->attestationStatementSupportManager;
    }

    /**
     * Serialize a credential record for storage.
     *
     * @throws InvalidDataException
     */
    public function serializeCredential(CredentialRecord $record): string
    {
        return $this->serializer()->serialize($record, 'json');
    }

    /**
     * Rebuild a credential record previously written by {@see serializeCredential()}.
     *
     * Returns null rather than throwing when the stored payload is unusable: a
     * corrupt entry must degrade to "this passkey is unavailable", never to a
     * 500 on the login page.
     */
    public function deserializeCredential(string $json): ?CredentialRecord
    {
        try {
            return $this->serializer()->deserialize($json, CredentialRecord::class, 'json');
        } catch (Throwable) {
            return null;
        }
    }
}
