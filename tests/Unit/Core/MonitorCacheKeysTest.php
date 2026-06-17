<?php
declare(strict_types=1);

namespace App\Tests\Unit\Core;

use App\MonitorCacheKeys;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class MonitorCacheKeysTest extends TestCase {
    public function testConstructorInitializesWhenKeyListMissing(): void {
        $pool = new ArrayAdapter();
        $monitor = new MonitorCacheKeys($pool);

        self::assertSame([], $monitor->getKeys());
        self::assertSame([], $monitor->getChanges());
    }

    public function testConstructorDoesNotInitializeWhenItemsExist(): void {
        $pool = new ArrayAdapter();
        $monitor = new MonitorCacheKeys($pool);
        $monitor->saveDeferred($pool->getItem('testkey'));
        $monitor->commit();

        // Create a new MonitorCacheKeys with the same pool - should not reinitialize
        $monitor2 = new MonitorCacheKeys($pool);
        self::assertSame(['testkey'], $monitor2->getKeys());
    }

    public function testGetKeys(): void {
        $pool = new ArrayAdapter();
        $monitor = new MonitorCacheKeys($pool);

        $item = $pool->getItem('key1');
        $item->set('value1');
        $monitor->save($item);

        $item2 = $pool->getItem('key2');
        $item2->set('value2');
        $monitor->save($item2);

        self::assertSame(['key1', 'key2'], $monitor->getKeys());
    }

    public function testGetChanges(): void {
        $pool = new ArrayAdapter();
        $monitor = new MonitorCacheKeys($pool);

        $item = $pool->getItem('key1');
        $item->set('value1');
        $monitor->save($item);

        self::assertSame(['key1' => MonitorCacheKeys::UPDATED], $monitor->getChanges());
    }

    public function testMarkClean(): void {
        $pool = new ArrayAdapter();
        $monitor = new MonitorCacheKeys($pool);

        $item = $pool->getItem('key1');
        $item->set('value1');
        $monitor->save($item);

        self::assertSame(['key1' => MonitorCacheKeys::UPDATED], $monitor->getChanges());
        $monitor->markClean();
        self::assertSame([], $monitor->getChanges());
    }

    public function testGetItemDelegates(): void {
        $pool = new ArrayAdapter();
        $monitor = new MonitorCacheKeys($pool);

        $item = $monitor->getItem('mykey');
        self::assertSame('mykey', $item->getKey());
    }

    public function testGetItemsDelegates(): void {
        $pool = new ArrayAdapter();
        $monitor = new MonitorCacheKeys($pool);

        $items = iterator_to_array($monitor->getItems(['a', 'b']));
        self::assertCount(2, $items);
        self::assertArrayHasKey('a', $items);
        self::assertArrayHasKey('b', $items);
    }

    public function testHasItemDelegates(): void {
        $pool = new ArrayAdapter();
        $monitor = new MonitorCacheKeys($pool);

        self::assertFalse($monitor->hasItem('mykey'));

        $item = $pool->getItem('mykey');
        $item->set('value');
        $monitor->save($item);

        self::assertTrue($monitor->hasItem('mykey'));
    }

    public function testClearWhenNotEmpty(): void {
        $pool = new ArrayAdapter();
        $monitor = new MonitorCacheKeys($pool);

        $item = $pool->getItem('key1');
        $item->set('value1');
        $monitor->save($item);

        self::assertTrue($monitor->clear());
        self::assertSame([], $monitor->getKeys());
    }

    public function testClearWhenEmpty(): void {
        $pool = new ArrayAdapter();
        $monitor = new MonitorCacheKeys($pool);

        self::assertTrue($monitor->clear());
    }

    public function testDeleteItemUpdatesKeyList(): void {
        $pool = new ArrayAdapter();
        $monitor = new MonitorCacheKeys($pool);

        $item = $pool->getItem('mykey');
        $item->set('value');
        $monitor->save($item);

        self::assertTrue($monitor->deleteItem('mykey'));
        self::assertSame(['mykey' => MonitorCacheKeys::REMOVED], $monitor->getChanges());
        self::assertFalse($monitor->hasItem('mykey'));
    }

    public function testDeleteItemThrowsOnPrivateKey(): void {
        $pool = new ArrayAdapter();
        $monitor = new MonitorCacheKeys($pool);

        $this->expectException(OutOfBoundsException::class);
        $monitor->deleteItem('__key_list');
    }

    public function testDeleteItemsUpdatesKeyList(): void {
        $pool = new ArrayAdapter();
        $monitor = new MonitorCacheKeys($pool);

        $item1 = $pool->getItem('a');
        $item1->set('value1');
        $monitor->save($item1);

        $item2 = $pool->getItem('b');
        $item2->set('value2');
        $monitor->save($item2);

        self::assertTrue($monitor->deleteItems(['a', 'b']));
        self::assertSame(['a' => MonitorCacheKeys::REMOVED, 'b' => MonitorCacheKeys::REMOVED], $monitor->getChanges());
    }

    public function testDeleteItemsThrowsOnPrivateKey(): void {
        $pool = new ArrayAdapter();
        $monitor = new MonitorCacheKeys($pool);

        $this->expectException(OutOfBoundsException::class);
        $monitor->deleteItems(['a', '__chg_list']);
    }

    public function testSaveUpdatesKeyList(): void {
        $pool = new ArrayAdapter();
        $monitor = new MonitorCacheKeys($pool);

        $item = $pool->getItem('newkey');
        $item->set('value');

        self::assertTrue($monitor->save($item));
        self::assertSame(['newkey' => MonitorCacheKeys::UPDATED], $monitor->getChanges());
        self::assertTrue($monitor->hasItem('newkey'));
    }

    public function testSaveThrowsOnPrivateKey(): void {
        $pool = new ArrayAdapter();
        $monitor = new MonitorCacheKeys($pool);

        $item = $pool->getItem('__key_list');
        $item->set('value');

        $this->expectException(OutOfBoundsException::class);
        $monitor->save($item);
    }

    public function testSaveDeferredUpdatesKeyList(): void {
        $pool = new ArrayAdapter();
        $monitor = new MonitorCacheKeys($pool);

        $item = $pool->getItem('newkey');
        $item->set('value');

        self::assertTrue($monitor->saveDeferred($item));
        self::assertSame(['newkey' => MonitorCacheKeys::UPDATED], $monitor->getChanges());
    }

    public function testCommitDelegates(): void {
        $pool = new ArrayAdapter();
        $monitor = new MonitorCacheKeys($pool);

        self::assertTrue($monitor->commit());
    }
}
