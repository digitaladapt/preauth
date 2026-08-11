<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\MonitorCacheKeys;
use App\PersistCache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class PersistCacheTest extends TestCase
{
    public function testBootWithEmptyStorageIsNoop(): void
    {
        $sessionCache = new ArrayAdapter();
        $sessionStorage = new ArrayAdapter();

        $persist = new PersistCache($sessionCache, $sessionStorage);
        $persist->boot();

        // nothing was loaded since storage is empty
        $monitor = new MonitorCacheKeys($sessionCache);
        self::assertSame([], $monitor->getKeys());
    }

    public function testBootLoadsFromStorageIntoCache(): void
    {
        $sessionCache = new ArrayAdapter();
        $sessionStorage = new ArrayAdapter();

        // populate storage with some session data
        $storageMonitor = new MonitorCacheKeys($sessionStorage);
        $item = $storageMonitor->getItem('cookie_abc');
        $item->set('user1');
        $storageMonitor->save($item);
        $storageMonitor->markClean();

        $persist = new PersistCache($sessionCache, $sessionStorage);
        $persist->boot();

        // session cache should now contain the loaded data
        $cacheMonitor = new MonitorCacheKeys($sessionCache);
        self::assertContains('cookie_abc', $cacheMonitor->getKeys());
        self::assertSame('user1', $cacheMonitor->getItem('cookie_abc')->get());
        // boot should mark clean so no changes are pending
        self::assertSame([], $cacheMonitor->getChanges());
    }

    public function testBootDoesNotReloadWhenCacheAlreadyWarm(): void
    {
        $sessionCache = new ArrayAdapter();
        $sessionStorage = new ArrayAdapter();

        // warm up the cache with existing data
        $cacheMonitor = new MonitorCacheKeys($sessionCache);
        $item = $cacheMonitor->getItem('cookie_existing');
        $item->set('old-user');
        $cacheMonitor->save($item);

        // put different data in storage
        $storageMonitor = new MonitorCacheKeys($sessionStorage);
        $item = $storageMonitor->getItem('cookie_new');
        $item->set('new-user');
        $storageMonitor->save($item);

        $persist = new PersistCache($sessionCache, $sessionStorage);
        $persist->boot();

        // existing data should be preserved, storage data NOT loaded
        $monitor = new MonitorCacheKeys($sessionCache);
        self::assertContains('cookie_existing', $monitor->getKeys());
        self::assertNotContains('cookie_new', $monitor->getKeys());
    }

    public function testPersistWritesChangesToStorage(): void
    {
        $sessionCache = new ArrayAdapter();
        $sessionStorage = new ArrayAdapter();

        $persist = new PersistCache($sessionCache, $sessionStorage);
        $persist->boot();

        // write something to the session cache
        $cacheMonitor = new MonitorCacheKeys($sessionCache);
        $item = $cacheMonitor->getItem('cookie_xyz');
        $item->set('user2');
        $cacheMonitor->save($item);

        $persist->persist();

        // storage should now contain the change
        $storageMonitor = new MonitorCacheKeys($sessionStorage);
        self::assertContains('cookie_xyz', $storageMonitor->getKeys());
        self::assertSame('user2', $storageMonitor->getItem('cookie_xyz')->get());
    }

    public function testPersistHandlesRemovals(): void
    {
        $sessionCache = new ArrayAdapter();
        $sessionStorage = new ArrayAdapter();

        // seed storage with an item
        $storageMonitor = new MonitorCacheKeys($sessionStorage);
        $item = $storageMonitor->getItem('cookie_to_remove');
        $item->set('user3');
        $storageMonitor->save($item);
        $storageMonitor->markClean();

        $persist = new PersistCache($sessionCache, $sessionStorage);
        $persist->boot();

        // now delete it from session cache
        $cacheMonitor = new MonitorCacheKeys($sessionCache);
        $cacheMonitor->deleteItem('cookie_to_remove');

        $persist->persist();

        // storage should no longer have it
        $storageMonitor = new MonitorCacheKeys($sessionStorage);
        self::assertNotContains('cookie_to_remove', $storageMonitor->getKeys());
    }

    public function testPersistIsNoopWhenNoChanges(): void
    {
        $sessionCache = new ArrayAdapter();
        $sessionStorage = new ArrayAdapter();

        $persist = new PersistCache($sessionCache, $sessionStorage);
        $persist->boot();
        $persist->persist();

        $storageMonitor = new MonitorCacheKeys($sessionStorage);
        self::assertSame([], $storageMonitor->getKeys());
    }

    public function testFullBootModifyPersistCycle(): void
    {
        $sessionCache = new ArrayAdapter();
        $sessionStorage = new ArrayAdapter();

        // boot (empty), add data, persist
        $persist = new PersistCache($sessionCache, $sessionStorage);
        $persist->boot();

        $cacheMonitor = new MonitorCacheKeys($sessionCache);
        $item = $cacheMonitor->getItem('cookie_cycle');
        $item->set('cycled-user');
        $cacheMonitor->save($item);

        $persist->persist();

        // simulate a new request: fresh cache, same storage
        $newCache = new ArrayAdapter();
        $persist2 = new PersistCache($newCache, $sessionStorage);
        $persist2->boot();

        $monitor = new MonitorCacheKeys($newCache);
        self::assertContains('cookie_cycle', $monitor->getKeys());
        self::assertSame('cycled-user', $monitor->getItem('cookie_cycle')->get());
    }

    public function testPersistHandlesMixedUpdatesAndRemovals(): void
    {
        $sessionCache = new ArrayAdapter();
        $sessionStorage = new ArrayAdapter();

        // seed storage with two items
        $storageMonitor = new MonitorCacheKeys($sessionStorage);
        $item1 = $storageMonitor->getItem('cookie_keep');
        $item1->set('user-keep');
        $storageMonitor->save($item1);
        $item2 = $storageMonitor->getItem('cookie_remove');
        $item2->set('user-remove');
        $storageMonitor->save($item2);
        $storageMonitor->markClean();

        $persist = new PersistCache($sessionCache, $sessionStorage);
        $persist->boot();

        // update one item and delete the other in the same cycle
        $cacheMonitor = new MonitorCacheKeys($sessionCache);
        $item1 = $cacheMonitor->getItem('cookie_keep');
        $item1->set('user-updated');
        $cacheMonitor->save($item1);
        $cacheMonitor->deleteItem('cookie_remove');

        $persist->persist();

        // storage should reflect both changes
        $storageMonitor = new MonitorCacheKeys($sessionStorage);
        self::assertContains('cookie_keep', $storageMonitor->getKeys());
        self::assertSame('user-updated', $storageMonitor->getItem('cookie_keep')->get());
        self::assertNotContains('cookie_remove', $storageMonitor->getKeys());
    }

    public function testMultipleBootModifyPersistCycles(): void
    {
        $sessionCache = new ArrayAdapter();
        $sessionStorage = new ArrayAdapter();

        // cycle 1: add item A
        $persist = new PersistCache($sessionCache, $sessionStorage);
        $persist->boot();
        $cacheMonitor = new MonitorCacheKeys($sessionCache);
        $item = $cacheMonitor->getItem('cookie_a');
        $item->set('user-a');
        $cacheMonitor->save($item);
        $persist->persist();

        // cycle 2: fresh cache, add item B, keep A from storage
        $newCache = new ArrayAdapter();
        $persist2 = new PersistCache($newCache, $sessionStorage);
        $persist2->boot();
        $cacheMonitor2 = new MonitorCacheKeys($newCache);
        $item = $cacheMonitor2->getItem('cookie_b');
        $item->set('user-b');
        $cacheMonitor2->save($item);
        $persist2->persist();

        // cycle 3: fresh cache, both A and B should be loaded from storage
        $newCache2 = new ArrayAdapter();
        $persist3 = new PersistCache($newCache2, $sessionStorage);
        $persist3->boot();
        $monitor = new MonitorCacheKeys($newCache2);
        self::assertContains('cookie_a', $monitor->getKeys());
        self::assertSame('user-a', $monitor->getItem('cookie_a')->get());
        self::assertContains('cookie_b', $monitor->getKeys());
        self::assertSame('user-b', $monitor->getItem('cookie_b')->get());
    }
}
