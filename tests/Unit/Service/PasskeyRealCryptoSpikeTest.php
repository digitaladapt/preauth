<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\PasskeyCeremonyFactory;
use App\Tests\Support\PasskeyTestHelper;
use PHPUnit\Framework\TestCase;
use Throwable;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Proves the ceremony verifies with real cryptography, before the manager is
 * built on top of it. If this file is green, the library is wired correctly and
 * a later failure is our logic rather than our configuration.
 *
 * Nothing is mocked: a real P-256 keypair signs a real `authenticatorData`, and
 * the CBOR attestation object is assembled exactly as an authenticator would.
 */
final class PasskeyRealCryptoSpikeTest extends TestCase
{
    private const string ORIGIN = 'https://auth.example.com';

    private const string RP_ID = 'example.com';

    /** The identity registered throughout; its handle is derived, not sent. */
    private const string IDENTITY = 'lyra';

    private static function userHandle(): string
    {
        return hash('sha256', self::IDENTITY, true);
    }

    private function factory(): PasskeyCeremonyFactory
    {
        return new PasskeyCeremonyFactory();
    }

    private function registrationOptions(string $challenge): PublicKeyCredentialCreationOptions
    {
        return new PublicKeyCredentialCreationOptions(
            new PublicKeyCredentialRpEntity('Preauth', self::RP_ID),
            new PublicKeyCredentialUserEntity(self::IDENTITY, self::userHandle(), self::IDENTITY),
            $challenge,
            [PublicKeyCredentialParameters::create('public-key', -7)],
            new AuthenticatorSelectionCriteria(
                AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_PLATFORM,
                'required',
                AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
            ),
            'none',
            [],
            60000,
        );
    }

    private function requestOptions(string $challenge): PublicKeyCredentialRequestOptions
    {
        return new PublicKeyCredentialRequestOptions($challenge, self::RP_ID, [], 'required', 60000);
    }

    /**
     * Run a registration and return the resulting record.
     *
     * @throws Throwable
     */
    private function register(PasskeyTestHelper $helper, string $credentialId): CredentialRecord
    {
        $challenge = random_bytes(32);
        $json = $helper->registrationCredential(self::RP_ID, $challenge, self::ORIGIN, $credentialId);

        $credential = $this->factory()->serializer()->denormalize($json, PublicKeyCredential::class, 'json');
        self::assertInstanceOf(PublicKeyCredential::class, $credential);
        self::assertInstanceOf(AuthenticatorAttestationResponse::class, $credential->response);

        return $this->factory()
            ->creationCeremonyValidator([self::ORIGIN])
            ->check($credential->response, $this->registrationOptions($challenge), self::RP_ID);
    }

    /**
     * @param string[] $allowedOrigins
     *
     * @throws Throwable
     */
    private function verifyAssertion(
        PasskeyTestHelper $helper,
        CredentialRecord $record,
        string $credentialId,
        string $challenge,
        int $counter,
        array $allowedOrigins = [self::ORIGIN],
    ): CredentialRecord {
        $json = $helper->assertionCredential(
            self::RP_ID,
            $challenge,
            self::ORIGIN,
            $credentialId,
            $counter,
            self::userHandle(),
        );

        $credential = $this->factory()->serializer()->denormalize($json, PublicKeyCredential::class, 'json');
        self::assertInstanceOf(PublicKeyCredential::class, $credential);
        self::assertInstanceOf(AuthenticatorAssertionResponse::class, $credential->response);

        return $this->factory()
            ->requestCeremonyValidator($allowedOrigins)
            ->check($record, $credential->response, $this->requestOptions($challenge), self::RP_ID, self::userHandle());
    }

    /**
     * The headline claim: a real registration is accepted.
     */
    public function test_a_real_registration_verifies(): void
    {
        $record = $this->register(new PasskeyTestHelper(), (new PasskeyTestHelper())->credentialId());

        self::assertSame('none', $record->attestationType);
        self::assertNotSame('', $record->credentialPublicKey);
    }

    /**
     * A real registration followed by a real assertion — the whole flow.
     */
    public function test_a_real_assertion_verifies_after_registration(): void
    {
        $helper = new PasskeyTestHelper();
        $credentialId = $helper->credentialId();
        $record = $this->register($helper, $credentialId);

        $counter = $record->counter + 1;
        $updated = $this->verifyAssertion($helper, $record, $credentialId, random_bytes(32), $counter);

        self::assertSame($counter, $updated->counter);
    }

    /**
     * D4 in action: the same assertion is refused when the allow-list says
     * `http://`. The library accepts only what it is told to accept, which is
     * exactly why no exemption may be reintroduced.
     */
    public function test_an_http_origin_is_rejected(): void
    {
        $helper = new PasskeyTestHelper();
        $credentialId = $helper->credentialId();
        $record = $this->register($helper, $credentialId);

        $this->expectException(Throwable::class);

        $this->verifyAssertion(
            $helper,
            $record,
            $credentialId,
            random_bytes(32),
            $record->counter + 1,
            ['http://auth.example.com'],
        );
    }

    /**
     * A tampered challenge must fail, or the ceremony would not bind the
     * assertion to this session.
     */
    public function test_a_different_challenge_is_rejected(): void
    {
        $helper = new PasskeyTestHelper();
        $credentialId = $helper->credentialId();
        $record = $this->register($helper, $credentialId);

        /* sign one challenge, present another */
        $json = $helper->assertionCredential(
            self::RP_ID,
            random_bytes(32),
            self::ORIGIN,
            $credentialId,
            $record->counter + 1,
            self::userHandle(),
        );
        $credential = $this->factory()->serializer()->denormalize($json, PublicKeyCredential::class, 'json');
        self::assertInstanceOf(PublicKeyCredential::class, $credential);
        self::assertInstanceOf(AuthenticatorAssertionResponse::class, $credential->response);

        $this->expectException(Throwable::class);

        $this->factory()
            ->requestCeremonyValidator([self::ORIGIN])
            ->check(
                $record,
                $credential->response,
                $this->requestOptions(random_bytes(32)),
                self::RP_ID,
                self::userHandle(),
            );
    }

    /**
     * A forged RP ID hash must fail, so a credential registered for one site
     * cannot be replayed at another.
     */
    public function test_a_forged_rp_id_hash_is_rejected(): void
    {
        $helper = new PasskeyTestHelper();
        $credentialId = $helper->credentialId();
        $record = $this->register($helper, $credentialId);

        $challenge = random_bytes(32);
        $json = $helper->assertionCredential(
            'evil.example.com',
            $challenge,
            self::ORIGIN,
            $credentialId,
            $record->counter + 1,
            self::userHandle(),
        );
        $credential = $this->factory()->serializer()->denormalize($json, PublicKeyCredential::class, 'json');
        self::assertInstanceOf(PublicKeyCredential::class, $credential);
        self::assertInstanceOf(AuthenticatorAssertionResponse::class, $credential->response);

        $this->expectException(Throwable::class);

        $this->factory()
            ->requestCeremonyValidator([self::ORIGIN])
            ->check($record, $credential->response, $this->requestOptions($challenge), self::RP_ID, self::userHandle());
    }

    /**
     * A credential reporting a constant zero counter must be able to log in
     * repeatedly. This is the regression the lenient checker exists for, and it
     * is asserted through the full library path rather than the checker alone —
     * the library's own default would fail this.
     */
    public function test_a_synchronised_passkey_with_a_zero_counter_can_log_in_repeatedly(): void
    {
        $helper = new PasskeyTestHelper();
        $credentialId = $helper->credentialId();
        $record = $this->register($helper, $credentialId);

        /* model a synchronised passkey: the counter never advances */
        $record->counter = 0;

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $updated = $this->verifyAssertion($helper, $record, $credentialId, random_bytes(32), 0);

            self::assertSame(0, $updated->counter, "attempt $attempt");
        }
    }

    /**
     * The same credential asserted from a sibling subdomain succeeds, because
     * the RP ID is the base domain. Asserted explicitly so the scope is
     * documented in code rather than only in the plan.
     */
    public function test_a_sibling_subdomain_cannot_reuse_a_credential_whose_origin_is_wrong(): void
    {
        $helper = new PasskeyTestHelper();
        $credentialId = $helper->credentialId();
        $record = $this->register($helper, $credentialId);

        $challenge = random_bytes(32);
        $json = $helper->assertionCredential(
            self::RP_ID,
            $challenge,
            'https://app.example.com',
            $credentialId,
            $record->counter + 1,
            self::userHandle(),
        );
        $credential = $this->factory()->serializer()->denormalize($json, PublicKeyCredential::class, 'json');
        self::assertInstanceOf(PublicKeyCredential::class, $credential);
        self::assertInstanceOf(AuthenticatorAssertionResponse::class, $credential->response);

        /* the auth subdomain's origin is the only one allowed, so a ceremony
         * driven from a sibling host is refused even though the RP ID matches */
        $this->expectException(Throwable::class);

        $this->factory()
            ->requestCeremonyValidator([self::ORIGIN])
            ->check($record, $credential->response, $this->requestOptions($challenge), self::RP_ID, self::userHandle());
    }
}
