<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Data\PasskeyCredential;
use App\MonitorCacheKeys;
use App\Service\PasskeyCeremonyFactory;
use App\Service\PasskeyCredentialStore;
use DateTimeImmutable;
use LogicException;
use Override;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * Covers credential persistence, the index, and the two failure modes the plan
 * called out: losing credentials on restart, and key collisions.
 */
final class PasskeyCredentialStoreTest extends TestCase
{
    /** The real backing pool, so persistence can be asserted against it. */
    private ?ArrayAdapter $pool = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->pool = new ArrayAdapter();
    }

    private function makeStore(): PasskeyCredentialStore
    {
        return new PasskeyCredentialStore($this->pool(), new PasskeyCeremonyFactory());
    }

    /** The pool for the current test; setUp() always assigns it. */
    private function pool(): ArrayAdapter
    {
        return $this->pool ?? throw new LogicException('setUp() did not run');
    }

    /** @param array{userHandle?: string, counter?: int, backupEligible?: ?bool} $overrides */
    private function makeCredential(
        string $credentialId,
        string $identity = 'lyra',
        string $label = 'Laptop',
        array $overrides = [],
    ): PasskeyCredential {
        $record = CredentialRecord::create(
            $credentialId,
            'public-key',
            ['internal'],
            'none',
            EmptyTrustPath::create(),
            Uuid::v4(),
            'COSE_PUBLIC_KEY_BYTES',
            $overrides['userHandle'] ?? 'user-handle',
            $overrides['counter'] ?? 0,
            null,
            $overrides['backupEligible'] ?? true,
            false,
            true,
        );

        return new PasskeyCredential($record, $identity, $label, new DateTimeImmutable('2026-01-01 12:00:00'));
    }

    public function test_save_then_find_round_trips_the_record(): void
    {
        $store = $this->makeStore();
        $credentialId = random_bytes(32);
        $store->save($this->makeCredential($credentialId));

        $found = $store->find($credentialId);

        self::assertNotNull($found);
        self::assertSame($credentialId, $found->record->publicKeyCredentialId);
        self::assertSame('lyra', $found->identity);
        self::assertSame('Laptop', $found->label);
        self::assertSame('COSE_PUBLIC_KEY_BYTES', $found->record->credentialPublicKey);
        self::assertSame('user-handle', $found->record->userHandle);
        self::assertTrue($found->record->backupEligible);
        self::assertNull($found->lastUsedAt);
    }

    public function test_find_returns_null_for_an_unknown_credential(): void
    {
        self::assertNull($this->makeStore()->find(random_bytes(32)));
    }

    public function test_all_returns_every_saved_credential(): void
    {
        $store = $this->makeStore();
        $store->save($this->makeCredential(random_bytes(32), label: 'One'));
        $store->save($this->makeCredential(random_bytes(32), label: 'Two'));
        $store->save($this->makeCredential(random_bytes(32), label: 'Three'));

        self::assertCount(3, $store->all());
        self::assertSame(3, $store->count());
    }

    public function test_find_by_identity_filters(): void
    {
        $store = $this->makeStore();
        $store->save($this->makeCredential(random_bytes(32), identity: 'lyra'));
        $store->save($this->makeCredential(random_bytes(32), identity: 'lyra'));
        $store->save($this->makeCredential(random_bytes(32), identity: 'someone-else'));

        self::assertCount(2, $store->findByIdentity('lyra'));
        self::assertCount(1, $store->findByIdentity('someone-else'));
        self::assertCount(0, $store->findByIdentity('nobody'));
    }

    public function test_remove_forgets_the_credential_and_the_index_entry(): void
    {
        $store = $this->makeStore();
        $credentialId = random_bytes(32);
        $store->save($this->makeCredential($credentialId));

        self::assertTrue($store->remove($credentialId));
        self::assertNull($store->find($credentialId));
        self::assertSame(0, $store->count());
        self::assertSame([], $store->all());
    }

    public function test_update_usage_refreshes_the_counter_and_last_used(): void
    {
        $store = $this->makeStore();
        $credentialId = random_bytes(32);
        $store->save($this->makeCredential($credentialId, overrides: ['counter' => 0]));

        /* the library updates the counter in place after a verified assertion */
        $used = $store->find($credentialId)->record;
        $used->counter = 7;
        $store->updateUsage($used);

        $found = $store->find($credentialId);
        self::assertSame(7, $found->record->counter);
        self::assertNotNull($found->lastUsedAt);
        /* metadata must be preserved, not reset by the usage update */
        self::assertSame('lyra', $found->identity);
        self::assertSame('Laptop', $found->label);
    }

    public function test_update_usage_ignores_unknown_credentials(): void
    {
        $store = $this->makeStore();
        $record = $this->makeCredential(random_bytes(32))->record;

        $store->updateUsage($record);

        self::assertSame(0, $store->count());
    }

    /**
     * Distinct credential ids must never share a cache slot.
     *
     * `makeCacheKey()` alone is not injective for the base64url alphabet
     * ("abc-def" and "abc_def" both sanitise to "abc_def"), so the store hashes
     * the id. These two ids differ only by '-' vs '_' on purpose.
     */
    public function test_credential_ids_that_differ_only_by_base64url_punctuation_do_not_collide(): void
    {
        $store = $this->makeStore();
        $store->save($this->makeCredential('abc-def', label: 'Dash'));
        $store->save($this->makeCredential('abc_def', label: 'Underscore'));

        self::assertSame(2, $store->count());
        self::assertSame('Dash', $store->find('abc-def')->label);
        self::assertSame('Underscore', $store->find('abc_def')->label);
    }

    /**
     * The plan's headline storage risk: without wrapping the pool in
     * MonitorCacheKeys, credentials would live only in the APCu-side pool and
     * vanish on the next restart, because PersistCache::persist() only flushes
     * keys a monitor recorded.
     */
    public function test_saved_credentials_are_visible_to_the_persistent_pool(): void
    {
        $store = $this->makeStore();
        $credentialId = random_bytes(32);
        $store->save($this->makeCredential($credentialId));

        $monitor = new MonitorCacheKeys($this->pool());
        $recorded = $monitor->getKeys();

        self::assertNotEmpty($recorded, 'the store must record its writes with MonitorCacheKeys');
        self::assertContains(
            'passkey_index',
            $recorded,
            'the index must be tracked so it is flushed to the persistent pool',
        );

        $changes = $monitor->getChanges();
        self::assertArrayHasKey('passkey_index', $changes);

        /* and the credential itself must be tracked, not just the index */
        $trackedCredentialKeys = array_filter(
            $recorded,
            static fn (string $key): bool => str_starts_with($key, 'passkey_cred_'),
        );
        self::assertNotEmpty($trackedCredentialKeys, 'the credential entry must be tracked too');
    }

    /**
     * A corrupt or foreign payload must degrade to "unavailable", never to a
     * crash on the login page.
     */
    public function test_a_corrupt_entry_is_skipped_rather_than_throwing(): void
    {
        $store = $this->makeStore();
        $credentialId = random_bytes(32);
        $store->save($this->makeCredential($credentialId));

        /* corrupt the stored record but leave the index intact */
        $key = 'passkey_cred_'.hash('sha256', $credentialId);
        $item = $this->pool()->getItem($key);
        $payload = $item->get();
        $payload['record'] = '{not valid json';
        $item->set($payload);
        $this->pool()->save($item);

        self::assertNull($store->find($credentialId));
        /* all() must not throw, it must simply omit the broken entry */
        self::assertSame([], $store->all());
    }

    /**
     * The record decides which credential id it belongs to; an index entry
     * pointing somewhere else must not be honoured.
     */
    public function test_a_record_that_disagrees_with_its_key_is_rejected(): void
    {
        $store = $this->makeStore();
        $real = random_bytes(32);
        $store->save($this->makeCredential($real));

        /* copy the payload to a different credential id's slot */
        $source = $this->pool()->getItem('passkey_cred_'.hash('sha256', $real));
        $otherId = random_bytes(32);
        $target = $this->pool()->getItem('passkey_cred_'.hash('sha256', $otherId));
        $target->set($source->get());
        $this->pool()->save($target);

        self::assertNull($store->find($otherId));
    }

    public function test_an_empty_index_reads_as_empty(): void
    {
        self::assertSame([], $this->makeStore()->all());
        self::assertSame(0, $this->makeStore()->count());
    }

    /**
     * A stored value that is not the expected structure (for example written by
     * a different version) must read as "no such credential".
     */
    public function test_a_payload_of_the_wrong_shape_reads_as_missing(): void
    {
        $store = $this->makeStore();
        $credentialId = random_bytes(32);
        $store->save($this->makeCredential($credentialId));

        $item = $this->pool()->getItem('passkey_cred_'.hash('sha256', $credentialId));
        $item->set('not-an-array');
        $this->pool()->save($item);

        self::assertNull($store->find($credentialId));
    }

    /** A payload missing one of the required metadata keys is also unusable. */
    public function test_a_payload_missing_metadata_reads_as_missing(): void
    {
        $store = $this->makeStore();
        $credentialId = random_bytes(32);
        $store->save($this->makeCredential($credentialId));

        $key = 'passkey_cred_'.hash('sha256', $credentialId);
        $item = $this->pool()->getItem($key);
        $payload = $item->get();
        unset($payload['identity']);
        $item->set($payload);
        $this->pool()->save($item);

        self::assertNull($store->find($credentialId));
    }
}
