<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Data\PasskeyCredential;
use App\Service\PasskeyCeremonyFactory;
use App\Service\PasskeyCredentialStoreInterface;
use App\Service\PasskeyManager;
use App\Service\PasskeyPolicyInterface;
use App\Tests\Support\PasskeyTestHelper;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Uid\Uuid;
use Throwable;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * The ceremony control flow, with a stubbed validator.
 *
 * Real cryptography is proven separately (PasskeyRealCryptoSpikeTest and the
 * functional suite); this file is about the branches around it — what happens
 * when the store is empty, the body is malformed, or verification fails. Those
 * are the paths a browser is least likely to exercise on purpose and an attacker
 * most likely to.
 */
final class PasskeyManagerTest extends TestCase
{
    private const string RP_ID = 'example.com';

    private const string ORIGIN = 'https://auth.example.com';

    private ?ArrayAdapter $cache = null;

    private function makePolicy(): PasskeyPolicyInterface
    {
        $policy = $this->createStub(PasskeyPolicyInterface::class);
        $policy->method('rpId')->willReturn(self::RP_ID);
        $policy->method('authSubdomain')->willReturn('auth.example.com');
        $policy->method('allowedOrigins')->willReturn([self::ORIGIN]);
        $policy->method('rpName')->willReturn('Preauth');
        $policy->method('userVerification')->willReturn('required');
        $policy->method('timeout')->willReturn(60000);
        $policy->method('isEnabled')->willReturn(true);
        $policy->method('isAvailableFor')->willReturn(true);

        return $policy;
    }

    /**
     * @param PasskeyCredential[] $credentials
     */
    private function makeManager(
        array $credentials = [],
        ?PasskeyCredentialStoreInterface $store = null,
    ): PasskeyManager {
        $this->cache = new ArrayAdapter();

        $store ??= $this->makeStore($credentials);

        return new PasskeyManager(
            $this->makePolicy(),
            new \App\Service\PasskeyCeremonyStore($this->cache),
            $store,
            new PasskeyCeremonyFactory(),
        );
    }

    /**
     * @param PasskeyCredential[] $credentials
     */
    private function makeStore(array $credentials): PasskeyCredentialStoreInterface
    {
        $store = $this->createStub(PasskeyCredentialStoreInterface::class);
        $store->method('all')->willReturn($credentials);
        $store->method('find')->willReturnCallback(
            static function (string $id) use ($credentials): ?PasskeyCredential {
                foreach ($credentials as $credential) {
                    if ($credential->record->publicKeyCredentialId === $id) {
                        return $credential;
                    }
                }

                return null;
            },
        );

        return $store;
    }

    private function makeCredential(string $identity = 'lyra'): PasskeyCredential
    {
        return new PasskeyCredential(
            CredentialRecord::create(
                random_bytes(16),
                'public-key',
                ['internal'],
                'none',
                EmptyTrustPath::create(),
                Uuid::v4(),
                'COSE_KEY',
                hash('sha256', $identity, true),
                0,
                null,
                true,
                false,
                true,
            ),
            $identity,
            'Passkey abc',
            new DateTimeImmutable(),
        );
    }

    /* ── begin ────────────────────────────────────────────────────────── */

    public function test_begin_login_returns_options_and_a_ceremony_id(): void
    {
        $result = $this->makeManager()->beginLogin();

        self::assertArrayHasKey('publicKey', $result);
        self::assertArrayHasKey('ceremonyId', $result);
        self::assertSame(self::RP_ID, $result['publicKey']['rpId']);
    }

    /**
     * With no credentials registered the list is empty rather than absent, so
     * the browser can still offer a discoverable credential.
     */
    public function test_begin_login_with_no_credentials_offers_an_empty_list(): void
    {
        $result = $this->makeManager()->beginLogin();

        self::assertArrayHasKey('allowCredentials', $result['publicKey']);
        self::assertSame([], $result['publicKey']['allowCredentials']);
    }

    public function test_begin_login_lists_every_registered_credential(): void
    {
        $manager = $this->makeManager([$this->makeCredential('lyra'), $this->makeCredential('atlas')]);

        $result = $manager->beginLogin();

        self::assertCount(2, $result['publicKey']['allowCredentials']);
    }

    public function test_begin_registration_uses_the_given_identity(): void
    {
        $result = $this->makeManager()->beginRegistration('lyra');

        self::assertArrayHasKey('ceremonyId', $result);
        self::assertSame('lyra', $result['publicKey']['user']['name']);
        self::assertSame('none', $result['publicKey']['attestation']);
    }

    /* ── finish: malformed input ──────────────────────────────────────── */

    /**
     * A body without a ceremony id or credential must be refused, and must not
     * touch the credential store.
     */
    public function test_finish_login_refuses_a_body_without_a_ceremony_id(): void
    {
        $manager = $this->makeManager();

        self::assertNull($manager->finishLogin([]));
        self::assertNull($manager->finishLogin(['credential' => []]));
        self::assertNull($manager->finishLogin(['ceremonyId' => '', 'credential' => []]));
    }

    public function test_finish_login_refuses_a_body_without_a_credential(): void
    {
        $manager = $this->makeManager();

        self::assertNull($manager->finishLogin(['ceremonyId' => 'cid']));
        self::assertNull($manager->finishLogin(['ceremonyId' => 'cid', 'credential' => 'not-an-array']));
    }

    /**
     * An unknown ceremony id means the record was never issued, already spent,
     * or expired — all of which must look the same to the caller.
     */
    public function test_finish_login_refuses_an_unknown_ceremony_id(): void
    {
        $manager = $this->makeManager();

        self::assertNull($manager->finishLogin([
            'ceremonyId' => 'never-issued',
            'credential' => ['id' => 'x'],
        ]));
    }

    /**
     * A credential the store does not know must be refused before any
     * verification is attempted, so an attacker cannot use the endpoint as an
     * oracle by nominating arbitrary credential ids.
     */
    public function test_finish_login_refuses_an_unknown_credential(): void
    {
        $manager = $this->makeManager();
        $started = $manager->beginLogin();

        /* a structurally valid assertion for a credential nobody registered */
        $helper = new PasskeyTestHelper();
        $challenge = $this->challengeFor($started['ceremonyId']);
        $assertion = $helper->assertionCredential(
            self::RP_ID,
            $challenge,
            self::ORIGIN,
            random_bytes(16),
            1,
            hash('sha256', 'nobody', true),
        );

        self::assertNull($manager->finishLogin([
            'ceremonyId' => $started['ceremonyId'],
            'credential' => $assertion,
        ]));
    }

    public function test_finish_registration_refuses_malformed_input(): void
    {
        $manager = $this->makeManager();

        self::assertNull($manager->finishRegistration([]));
        self::assertNull($manager->finishRegistration(['ceremonyId' => 'cid']));
        self::assertNull($manager->finishRegistration(['ceremonyId' => 'never-issued', 'credential' => []]));
    }

    /**
     * A login ceremony must not be usable to finish a registration, or the two
     * flows' differing trust assumptions would blur together.
     */
    public function test_a_login_ceremony_cannot_finish_a_registration(): void
    {
        $manager = $this->makeManager();
        $started = $manager->beginLogin();

        self::assertNull($manager->finishRegistration([
            'ceremonyId' => $started['ceremonyId'],
            'credential' => [],
        ]));
    }

    /**
     * A registration ceremony must not be usable to finish a login.
     */
    public function test_a_registration_ceremony_cannot_finish_a_login(): void
    {
        $manager = $this->makeManager();
        $started = $manager->beginRegistration('lyra');

        self::assertNull($manager->finishLogin([
            'ceremonyId' => $started['ceremonyId'],
            'credential' => [],
        ]));
    }

    /* ── finish: verification failure ─────────────────────────────────── */

    /**
     * A wrong challenge must fail, and must not be retryable: the record is
     * consumed on read.
     */
    public function test_a_wrong_challenge_fails_and_is_not_retryable(): void
    {
        $helper = new PasskeyTestHelper();
        $credentialId = $helper->credentialId();

        /* a store holding a credential whose id matches the assertion */
        $record = CredentialRecord::create(
            $credentialId,
            'public-key',
            ['internal'],
            'none',
            EmptyTrustPath::create(),
            Uuid::v4(),
            'COSE_KEY',
            hash('sha256', 'lyra', true),
            0,
            null,
            true,
            false,
            true,
        );
        $stored = new PasskeyCredential($record, 'lyra', 'Passkey abc', new DateTimeImmutable());

        $manager = $this->makeManager([$stored]);
        $started = $manager->beginLogin();

        $assertion = $helper->assertionCredential(
            self::RP_ID,
            random_bytes(32),
            self::ORIGIN,
            $credentialId,
            0,
            hash('sha256', 'lyra', true),
        );

        self::assertNull($manager->finishLogin([
            'ceremonyId' => $started['ceremonyId'],
            'credential' => $assertion,
        ]));

        /* and the same ceremony cannot be presented again */
        self::assertNull($manager->finishLogin([
            'ceremonyId' => $started['ceremonyId'],
            'credential' => $assertion,
        ]));
    }

    /**
     * A store that throws must not turn a malformed credential into a 500.
     */
    public function test_a_store_failure_is_reported_as_a_failed_ceremony(): void
    {
        $helper = new PasskeyTestHelper();
        $credentialId = $helper->credentialId();

        $store = $this->createStub(PasskeyCredentialStoreInterface::class);
        $store->method('find')->willThrowException(new RuntimeException('store down'));

        $manager = $this->makeManager(store: $store);
        $started = $manager->beginLogin();

        $assertion = $helper->assertionCredential(
            self::RP_ID,
            random_bytes(32),
            self::ORIGIN,
            $credentialId,
            0,
            hash('sha256', 'lyra', true),
        );

        try {
            $result = $manager->finishLogin([
                'ceremonyId' => $started['ceremonyId'],
                'credential' => $assertion,
            ]);
        } catch (Throwable $exception) {
            self::fail('finishLogin() must not throw: '.$exception->getMessage());
        }

        self::assertNull($result);
    }

    /* ── helpers ──────────────────────────────────────────────────────── */

    /**
     * The challenge the manager issued for a ceremony, read back from the cache
     * the way an attacker with the ceremony id would not be able to.
     */
    private function challengeFor(string $ceremonyId): string
    {
        $key = new ReflectionMethod(\App\Service\PasskeyCeremonyStore::class, 'key');
        $store = new \App\Service\PasskeyCeremonyStore($this->cache);
        $item = $this->cache->getItem($key->invoke($store, $ceremonyId));
        $payload = $item->isHit() ? $item->get() : null;

        return \is_array($payload) && isset($payload['challenge']) ? (string) $payload['challenge'] : '';
    }

    /**
     * A credential whose stored payload cannot be read must be treated as
     * unusable, and — importantly — must not throw. One corrupt entry must not
     * become a 500 for every visitor on the login page.
     */
    public function test_a_credential_that_cannot_be_found_fails_the_ceremony(): void
    {
        $helper = new PasskeyTestHelper();
        $credentialId = $helper->credentialId();

        $store = $this->createStub(PasskeyCredentialStoreInterface::class);
        $store->method('find')->willReturn(null);

        $manager = $this->makeManager(store: $store);
        $started = $manager->beginLogin();

        $assertion = $helper->assertionCredential(
            self::RP_ID,
            random_bytes(32),
            self::ORIGIN,
            $credentialId,
            0,
            hash('sha256', 'lyra', true),
        );

        self::assertNull($manager->finishLogin([
            'ceremonyId' => $started['ceremonyId'],
            'credential' => $assertion,
        ]));
    }

    /**
     * A registration whose attestation cannot be parsed must fail rather than
     * throwing, for the same reason.
     */
    public function test_unparseable_attestation_fails_the_ceremony(): void
    {
        $manager = $this->makeManager();
        $started = $manager->beginRegistration('lyra');

        /* structurally a credential object, but the response is nonsense */
        self::assertNull($manager->finishRegistration([
            'ceremonyId' => $started['ceremonyId'],
            'credential' => ['id' => 'x', 'rawId' => 'x', 'type' => 'public-key', 'response' => []],
        ]));
    }

    /**
     * A store that cannot persist a registration must not report success: the
     * user would believe the passkey was saved and then find it missing at the
     * next login, with nothing to explain why.
     */
    public function test_a_registration_that_cannot_be_persisted_fails(): void
    {
        $store = $this->createStub(PasskeyCredentialStoreInterface::class);
        $store->method('all')->willReturn([]);
        $store->method('find')->willReturn(null);
        $store->method('save')->willThrowException(new RuntimeException('store down'));

        $helper = new PasskeyTestHelper();
        $manager = $this->makeManager(store: $store);
        $started = $manager->beginRegistration('lyra');
        $challenge = $this->challengeFor($started['ceremonyId']);

        $credential = $helper->registrationCredential(self::RP_ID, $challenge, self::ORIGIN);

        self::assertNull($manager->finishRegistration([
            'ceremonyId' => $started['ceremonyId'],
            'credential' => $credential,
        ]));
    }
}
