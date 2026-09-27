<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\PasskeyCeremonyStore;
use App\Service\PasskeyCeremonyStoreInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * The ceremony store holds the two properties the whole flow depends on: the
 * challenge is server-authoritative, and it can only be used once.
 */
final class PasskeyCeremonyStoreTest extends TestCase
{
    private function makeStore(?ArrayAdapter $cache = null): PasskeyCeremonyStore
    {
        return new PasskeyCeremonyStore($cache ?? new ArrayAdapter());
    }

    public function test_it_starts_a_login_ceremony(): void
    {
        $ceremony = $this->makeStore()->startLogin();

        self::assertArrayHasKey('ceremonyId', $ceremony);
        self::assertArrayHasKey('challenge', $ceremony);
        self::assertSame(32, \strlen($ceremony['challenge']));
        self::assertNotSame('', $ceremony['ceremonyId']);
    }

    public function test_it_starts_a_registration_ceremony_with_a_derived_user_handle(): void
    {
        $ceremony = $this->makeStore()->startRegistration('lyra');

        self::assertSame(hash('sha256', 'lyra', true), $ceremony['userHandle']);
        /* the handle must not be the raw identity, or the label leaks into the
         * authenticator's credential list */
        self::assertStringNotContainsString('lyra', $ceremony['userHandle']);
    }

    public function test_each_ceremony_gets_a_distinct_id_and_challenge(): void
    {
        $store = $this->makeStore();

        $first = $store->startLogin();
        $second = $store->startLogin();

        self::assertNotSame($first['ceremonyId'], $second['ceremonyId']);
        self::assertNotSame($first['challenge'], $second['challenge']);
    }

    public function test_consume_returns_the_stored_challenge(): void
    {
        $store = $this->makeStore();
        $ceremony = $store->startLogin();

        $consumed = $store->consume($ceremony['ceremonyId'], PasskeyCeremonyStoreInterface::TYPE_LOGIN);

        self::assertNotNull($consumed);
        self::assertSame($ceremony['challenge'], $consumed['challenge']);
    }

    /**
     * The replay guard. A second consume must fail, otherwise an observed
     * `finish` could be replayed against the same challenge.
     */
    public function test_a_ceremony_can_only_be_consumed_once(): void
    {
        $store = $this->makeStore();
        $ceremony = $store->startLogin();

        self::assertNotNull($store->consume($ceremony['ceremonyId'], PasskeyCeremonyStoreInterface::TYPE_LOGIN));
        self::assertNull($store->consume($ceremony['ceremonyId'], PasskeyCeremonyStoreInterface::TYPE_LOGIN));
    }

    public function test_an_unknown_id_yields_null(): void
    {
        self::assertNull($this->makeStore()->consume('never-issued', PasskeyCeremonyStoreInterface::TYPE_LOGIN));
    }

    /**
     * A registration ceremony must not be usable to complete a login, or the
     * two flows' differing trust assumptions would blur together.
     */
    public function test_a_ceremony_cannot_be_used_for_the_other_type(): void
    {
        $store = $this->makeStore();
        $registration = $store->startRegistration('lyra');

        self::assertNull($store->consume($registration['ceremonyId'], PasskeyCeremonyStoreInterface::TYPE_LOGIN));
        /* and it was destroyed by that failed attempt, so it cannot be retried
         * with the correct type either */
        self::assertNull($store->consume($registration['ceremonyId'], PasskeyCeremonyStoreInterface::TYPE_REGISTER));
    }

    /**
     * `makeCacheKey()` sanitises the base64url alphabet into `_`, so using it
     * directly on an id would let two distinct ids collide on one cache slot.
     * The store hashes instead; this asserts distinct ids stay distinct.
     */
    public function test_distinct_ids_never_share_a_cache_key(): void
    {
        $cache = new ArrayAdapter();
        $store = $this->makeStore($cache);

        $ids = [];
        for ($i = 0; $i < 50; ++$i) {
            $ids[] = $store->startRegistration("user$i")['ceremonyId'];
        }

        self::assertCount(50, array_unique($ids));
        foreach ($ids as $id) {
            self::assertNotNull($store->consume($id, PasskeyCeremonyStoreInterface::TYPE_REGISTER));
        }
    }

    public function test_ceremonies_do_not_survive_the_cache_being_cleared(): void
    {
        $cache = new ArrayAdapter();
        $store = $this->makeStore($cache);
        $ceremony = $store->startLogin();

        /* stand-in for expiry/eviction: a ceremony must not outlive its TTL, and
         * `nonceCache` is APCu precisely so it does not survive a restart */
        $cache->clear();

        self::assertNull($store->consume($ceremony['ceremonyId'], PasskeyCeremonyStoreInterface::TYPE_LOGIN));
    }

    /**
     * Malformed cache contents must degrade to "no ceremony" rather than
     * throwing into the listener, which would surface as a 500 on the login page.
     */
    public function test_a_malformed_record_is_treated_as_absent(): void
    {
        $cache = new ArrayAdapter();
        $store = $this->makeStore($cache);

        /* reach the private key derivation so the malformed value lands exactly
         * where a real ceremony record would */
        $key = new ReflectionMethod(PasskeyCeremonyStore::class, 'key');
        $item = $cache->getItem($key->invoke($store, 'bogus'));
        $item->set('not-an-array');
        $cache->save($item);

        self::assertNull($store->consume('bogus', PasskeyCeremonyStoreInterface::TYPE_LOGIN));
    }
}
