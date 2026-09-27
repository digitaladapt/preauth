<?php

declare(strict_types=1);

namespace App\Service;

use App\Data\PasskeyCredential;
use App\Service\PasskeyCeremonyStoreInterface as Ceremonies;
use DateTimeImmutable;
use Override;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Throwable;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Owns both WebAuthn ceremonies.
 *
 * **Library containment.** Every `Webauthn\*` type used by the application is
 * referenced in this file and {@see PasskeyCeremonyFactory}, so a v6 rename
 * touches two files rather than the whole codebase.
 *
 * **What is never trusted.** The challenge (server-authoritative, single-use —
 * see {@see PasskeyCeremonyStore}), the `userHandle` (read from the stored
 * credential, never from the client), and the credential id (it selects which
 * stored record to verify against). The library performs the cryptographic
 * verification of origin, RP ID hash, challenge, user presence/verification and
 * signature; this class supplies the inputs and interprets the outcome.
 */
final readonly class PasskeyManager implements PasskeyInterface
{
    /** ES256 is mandatory for WebAuthn; other algorithms buy nothing here. */
    private const int COSE_ALGORITHM_ES256 = -7;

    public function __construct(
        private PasskeyPolicyInterface $policy,
        private Ceremonies $ceremonies,
        private PasskeyCredentialStoreInterface $credentials,
        private PasskeyCeremonyFactory $factory,
    ) {
    }

    /**
     * Step 1 of the login ceremony.
     *
     * @return array{publicKey: mixed, ceremonyId: string}
     *
     * @throws Throwable
     */
    #[Override]
    public function beginLogin(): array
    {
        $ceremony = $this->ceremonies->startLogin();

        $options = new PublicKeyCredentialRequestOptions(
            $ceremony['challenge'],
            $this->policy->rpId(),
            $this->credentialDescriptors(),
            $this->policy->userVerification(),
            $this->policy->timeout(),
        );

        return [
            'publicKey' => $this->factory->optionsAsArray($options),
            'ceremonyId' => $ceremony['ceremonyId'],
        ];
    }

    /**
     * Step 3 of the login ceremony: verify the assertion and report the identity.
     *
     * Returns null on any failure — the caller must not be able to distinguish
     * "unknown credential" from "bad signature" from "wrong origin", or the
     * endpoint becomes an oracle for credential enumeration.
     *
     * @param array<string,mixed> $body
     *
     * @throws Throwable
     */
    #[Override]
    public function finishLogin(array $body): ?PasskeyCredential
    {
        $ceremonyId = $this->stringOrNull($body['ceremonyId'] ?? null);
        $credentialJson = $body['credential'] ?? null;

        if (null === $ceremonyId || !\is_array($credentialJson)) {
            return null;
        }

        $ceremony = $this->ceremonies->consume($ceremonyId, Ceremonies::TYPE_LOGIN);
        if (null === $ceremony) {
            return null;
        }

        $publicKeyCredential = $this->deserializeCredential($credentialJson);
        if (!$publicKeyCredential instanceof PublicKeyCredential
            || !$publicKeyCredential->response instanceof AuthenticatorAssertionResponse
        ) {
            return null;
        }

        /* the credential id selects the record: an attacker cannot nominate a
         * different credential than the one they hold the key for.
         *
         * Inside the try: a cache failure must degrade to "this passkey is
         * unavailable", never to a 500 on the login page. */
        try {
            $stored = $this->credentials->find($publicKeyCredential->rawId);
            if (null === $stored) {
                return null;
            }

            $updated = $this->factory
                ->requestCeremonyValidator($this->policy->allowedOrigins())
                ->check(
                    $stored->record,
                    $publicKeyCredential->response,
                    $this->requestOptions($ceremony['challenge']),
                    $this->policy->authSubdomain(),
                    $stored->record->userHandle,
                );
        } catch (Throwable) {
            /* deliberately not surfaced: see the note above */
            return null;
        }

        $this->credentials->updateUsage($updated);

        return $stored->withUsage($updated, new DateTimeImmutable());
    }

    /**
     * Step 1 of the registration ceremony. Only reachable once the caller has
     * already proven possession of the TOTP secret (see the flow in the plan).
     *
     * @return array{publicKey: mixed, ceremonyId: string}
     *
     * @throws Throwable
     */
    #[Override]
    public function beginRegistration(string $identity): array
    {
        $ceremony = $this->ceremonies->startRegistration($identity);

        return [
            'publicKey' => $this->factory->optionsAsArray($this->creationOptions(
                $ceremony['challenge'],
                $identity,
                $ceremony['userHandle'],
            )),
            'ceremonyId' => $ceremony['ceremonyId'],
        ];
    }

    /**
     * Step 3 of the registration ceremony: verify the attestation and persist
     * the credential under the identity that was authenticated in step 1.
     *
     * @param array<string,mixed> $body
     *
     * @throws Throwable
     */
    #[Override]
    public function finishRegistration(array $body): ?PasskeyCredential
    {
        $ceremonyId = $this->stringOrNull($body['ceremonyId'] ?? null);
        $credentialJson = $body['credential'] ?? null;

        if (null === $ceremonyId || !\is_array($credentialJson)) {
            return null;
        }

        $ceremony = $this->ceremonies->consume($ceremonyId, Ceremonies::TYPE_REGISTER);
        if (null === $ceremony || !isset($ceremony['identity'], $ceremony['userHandle'])) {
            return null;
        }

        $publicKeyCredential = $this->deserializeCredential($credentialJson);
        if (!$publicKeyCredential instanceof PublicKeyCredential
            || !$publicKeyCredential->response instanceof AuthenticatorAttestationResponse
        ) {
            return null;
        }

        try {
            $record = $this->factory
                ->creationCeremonyValidator($this->policy->allowedOrigins())
                ->check(
                    $publicKeyCredential->response,
                    $this->creationOptions(
                        $ceremony['challenge'],
                        $ceremony['identity'],
                        $ceremony['userHandle'],
                    ),
                    $this->policy->authSubdomain(),
                );
        } catch (Throwable) {
            return null;
        }

        $credential = new PasskeyCredential(
            $record,
            $ceremony['identity'],
            $this->labelFor($record),
            new DateTimeImmutable(),
        );

        try {
            $this->credentials->save($credential);
        } catch (Throwable) {
            /* a store that cannot persist a credential must not report success:
             * the user would believe the passkey was registered and then find it
             * missing at the next login */
            return null;
        }

        return $credential;
    }

    private function requestOptions(string $challenge): PublicKeyCredentialRequestOptions
    {
        return new PublicKeyCredentialRequestOptions(
            $challenge,
            $this->policy->rpId(),
            $this->credentialDescriptors(),
            /* userVerification must be present for the library to enforce the
             * user-verified flag; it is not re-read from the client request */
            $this->policy->userVerification(),
            $this->policy->timeout(),
        );
    }

    private function creationOptions(string $challenge, string $identity, string $userHandle): PublicKeyCredentialCreationOptions
    {
        return new PublicKeyCredentialCreationOptions(
            new PublicKeyCredentialRpEntity($this->policy->rpName(), $this->policy->rpId()),
            new PublicKeyCredentialUserEntity($identity, $userHandle, $identity),
            $challenge,
            [PublicKeyCredentialParameters::create('public-key', self::COSE_ALGORITHM_ES256)],
            new AuthenticatorSelectionCriteria(
                /* a passkey for this device, which is what the "register this
                 * device" checkbox promises */
                AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_PLATFORM,
                $this->policy->userVerification(),
                AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
            ),
            /* D5: 'none' — see the plan §2.3 and SECURITY.md */
            'none',
            /* also discourage registering the same device twice */
            $this->credentialDescriptors(),
            $this->policy->timeout(),
        );
    }

    /**
     * Descriptors for every stored credential, used both as `allowCredentials`
     * on login and as `excludeCredentials` on registration.
     *
     * @return PublicKeyCredentialDescriptor[]
     */
    private function credentialDescriptors(): array
    {
        $descriptors = [];
        foreach ($this->credentials->all() as $credential) {
            $descriptors[] = PublicKeyCredentialDescriptor::create(
                'public-key',
                $credential->record->publicKeyCredentialId,
                $credential->record->transports,
            );
        }

        return $descriptors;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function deserializeCredential(array $data): ?PublicKeyCredential
    {
        try {
            return $this->factory->serializer()->denormalize($data, PublicKeyCredential::class, 'json');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A short, stable description so a user can tell their keys apart.
     *
     * Derived from the credential id because the AAGUID is not a reliable
     * identity without the FIDO metadata service, which D5 deliberately does
     * not use.
     */
    private function labelFor(CredentialRecord $record): string
    {
        return 'Passkey '.substr(Base64UrlSafe::encodeUnpadded($record->publicKeyCredentialId), 0, 8);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }
}
