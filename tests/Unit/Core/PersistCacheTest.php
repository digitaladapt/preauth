<?php
declare(strict_types=1);

namespace App\Tests\Unit\Core;

use App\MonitorCacheKeys;
use App\PersistCache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class PersistCacheTest extends TestCase {
    public function testBootWhenCacheIsEmpty(): void {
        $sessionCache = new ArrayAdapter();
        $sessionStorage = new ArrayAdapter();

        // Pre-populate storage using MonitorCacheKeys so keys are tracked
        $storageMonitor = new MonitorCacheKeys($sessionStorage);
        $item = $storageMonitor->getItem('item1');
        $item->set('value1');
        $storageMonitor->save($item);

        $persistCache = new PersistCache($sessionCache, $sessionStorage);
        $persistCache->boot();

        // After boot, sessionCache should have the item from storage
        $cacheMonitor = new MonitorCacheKeys($sessionCache);
        self::assertTrue($cacheMonitor->hasItem('item1'));
    }

    public function testBootWhenCacheIsNotEmpty(): void {
        $sessionCache = new ArrayAdapter();
        $sessionStorage = new ArrayAdapter();

        // Pre-populate cache using MonitorCacheKeys so keys are tracked
        $cacheMonitor = new MonitorCacheKeys($sessionCache);
        $item = $cacheMonitor->getItem('item1');
        $item->set('value1');
        $cacheMonitor->save($item);

        $persistCache = new PersistCache($sessionCache, $sessionStorage);
        $persistCache->boot();

        // Cache should still have its item
        self::assertTrue($cacheMonitor->hasItem('item1'));
    }

    public function testPersistWithChanges(): void {
        $sessionCache = new ArrayAdapter();
        $sessionStorage = new ArrayAdapter();

        $persistCache = new PersistCache($sessionCache, $sessionStorage);
        $persistCache->boot();

        // Add items through MonitorCacheKeys so changes are tracked
        $cacheMonitor = new MonitorCacheKeys($sessionCache);
        $item = $cacheMonitor->getItem('item1');
        $item->set('value1');
        $cacheMonitor->save($item);

        $item2 = $cacheMonitor->getItem('item2');
        $item2->set('value2');
        $cacheMonitor->save($item2);
        $cacheMonitor->deleteItem('item2');

        $persistCache->persist();

        // Storage should now have item1 but not item2
        $storageMonitor = new MonitorCacheKeys($sessionStorage);
        self::assertTrue($storageMonitor->hasItem('item1'));
        self::assertFalse($storageMonitor->hasItem('item2'));
    }

    public function testPersistWithNoChanges(): void {
        $sessionCache = new ArrayAdapter();
        $sessionStorage = new ArrayAdapter();

        $persistCache = new PersistCache($sessionCache, $sessionStorage);
        $persistCache->boot();

        // Persist with no changes should work fine
        $persistCache->persist();

        self::assertTrue(true); // No exception thrown
    }
}
