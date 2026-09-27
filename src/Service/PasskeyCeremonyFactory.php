<?php

declare(strict_types=1);

namespace App\Service;

use Cose\Algorithm\Manager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use RuntimeException;
use Symfony\Component\Serializer\Serializer;
use Throwable;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\Exception\InvalidDataException;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

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

    private PasskeyCounterChecker $counterChecker;

    public function __construct()
    {
        $manager = AttestationStatementSupportManager::create();
        $manager->add(NoneAttestationStatementSupport::create());
        $this->attestationStatementSupportManager = $manager;
        $this->counterChecker = new PasskeyCounterChecker();
    }

    /**
     * The wire form of the ceremony options, ready to JSON-encode for the client.
     *
     * Goes through the serializer rather than `json_encode()`, because the
     * challenge is raw binary: `json_encode()` rejects it outright, and the
     * serializer base64url-encodes exactly the fields the browser expects. Using
     * one path here and another at verification time is how a challenge silently
     * stops matching, so both go through this factory.
     *
     * @return array<string,mixed>
     */
    public function optionsAsArray(PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options): array
    {
        return $this->serializer()->normalize($options, 'json');
    }

    /**
     * Validator for the registration ceremony.
     *
     * The origins are passed in rather than read from a request, so the scheme
     * and host can only ever come from configuration. This is what makes D4
     * enforceable: an `http://` origin is never presented to the library as
     * acceptable, no matter how the request arrived at the container.
     *
     * @param string[] $allowedOrigins
     */
    public function creationCeremonyValidator(array $allowedOrigins): AuthenticatorAttestationResponseValidator
    {
        return AuthenticatorAttestationResponseValidator::create(
            $this->ceremonyStepManagerFactory($allowedOrigins)->creationCeremony(),
        );
    }

    /**
     * Validator for the login (assertion) ceremony.
     *
     * @param string[] $allowedOrigins
     */
    public function requestCeremonyValidator(array $allowedOrigins): AuthenticatorAssertionResponseValidator
    {
        return AuthenticatorAssertionResponseValidator::create(
            $this->ceremonyStepManagerFactory($allowedOrigins)->requestCeremony(),
        );
    }

    /**
     * The ceremony steps shared by both ceremonies.
     *
     * `setSecuredRelyingPartyId()` is deliberately never called: it is deprecated
     * in 5.2 and, more importantly, it is the escape hatch that would let an
     * `http://` origin through. Development uses real TLS instead (D4).
     *
     * @param string[] $allowedOrigins
     */
    private function ceremonyStepManagerFactory(array $allowedOrigins): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        $factory->setAllowedOrigins($allowedOrigins);
        $factory->setAlgorithmManager(Manager::create()->add(ES256::create()));
        $factory->setAttestationStatementSupportManager($this->attestationStatementSupportManager);
        /* replace the library default, which rejects the constant-zero counter
         * that synchronised passkeys report — see PasskeyCounterChecker */
        $factory->setCounterChecker($this->counterChecker);

        return $factory;
    }

    /**
     * The serializer for every WebAuthn value: credential records, ceremony
     * options, and the client's response.
     *
     * This must be used instead of `json_encode()`. Options carry raw binary
     * (the challenge) which `json_encode()` rejects outright, and
     * `CredentialRecord` is not `JsonSerializable` at all — the serializer
     * base64url-encodes those fields and is required for a correct round-trip.
     *
     * The concrete `Serializer` is returned because the library's own return
     * type (`SerializerInterface`) only declares `serialize()`/`deserialize()`,
     * while this class also needs `normalize()`/`denormalize()`.
     */
    public function serializer(): Serializer
    {
        $serializer = (new WebauthnSerializerFactory($this->attestationStatementSupportManager))->create();

        /* the library's declared return type is the narrower interface, so this
         * narrows it back — failing loudly if a future version ever returns
         * something else, rather than erroring at the first ceremony */
        if (!$serializer instanceof Serializer) {
            throw new RuntimeException('Expected the WebAuthn serializer to be a '.Serializer::class.'.');
        }

        return $serializer;
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
