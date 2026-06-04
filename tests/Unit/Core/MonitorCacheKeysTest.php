<?php
declare(strict_types=1);

namespace App\Tests\Unit\Core;

use App\MonitorCacheKeys;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

final class MonitorCacheKeysTest extends TestCase {
    private function createMockPool(): CacheItemPoolInterface {
        return $this->createMock(CacheItemPoolInterface::class);
    }

    private function createMockItem(string $key, mixed $value = null, bool $isHit = true): CacheItemInterface {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('getKey')->willReturn($key);
        $item->method('get')->willReturn($value);
        $item->method('isHit')->willReturn($isHit);
        return $item;
    }

    public function testConstructorInitializesWhenKeyListMissing(): void {
        $pool = $this->createMockPool();
        $keyListItem = $this->createMockItem('__key_list', null, false);
        $changeListItem = $this->createMockItem('__chg_list', [], true);

        $pool->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);

        $pool->expects(self::exactly(2))
            ->method('saveDeferred')
            ->willReturnCallback(function ($item) {
                self::assertContains($item->getKey(), ['__key_list', '__chg_list']);
                return true;
            });
        $pool->expects(self::once())->method('commit')->willReturn(true);

        new MonitorCacheKeys($pool);
    }

    public function testConstructorDoesNotInitializeWhenItemsExist(): void {
        $pool = $this->createMockPool();
        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);

        $pool->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);

        $pool->expects(self::never())->method('saveDeferred');
        $pool->expects(self::never())->method('commit');

        new MonitorCacheKeys($pool);
    }

    public function testGetKeys(): void {
        $pool = $this->createMockPool();
        $keyListItem = $this->createMockItem('__key_list', ['key1' => true, 'key2' => true], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);

        $pool->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);
        $pool->method('getItem')
            ->with('__key_list')
            ->willReturn($keyListItem);

        $monitor = new MonitorCacheKeys($pool);
        self::assertSame(['key1', 'key2'], $monitor->getKeys());
    }

    public function testGetChanges(): void {
        $pool = $this->createMockPool();
        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', ['key1' => MonitorCacheKeys::UPDATED], true);

        $pool->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);
        $pool->method('getItem')
            ->with('__chg_list')
            ->willReturn($changeListItem);

        $monitor = new MonitorCacheKeys($pool);
        self::assertSame(['key1' => MonitorCacheKeys::UPDATED], $monitor->getChanges());
    }

    public function testMarkClean(): void {
        $pool = $this->createMockPool();
        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', ['key1' => MonitorCacheKeys::UPDATED], true);

        $pool->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);
        $pool->method('getItem')
            ->with('__chg_list')
            ->willReturn($changeListItem);
        $pool->expects(self::once())
            ->method('save')
            ->with(self::callback(function ($item) {
                return $item->getKey() === '__chg_list' && $item->get() === [];
            }))
            ->willReturn(true);

        $monitor = new MonitorCacheKeys($pool);
        $monitor->markClean();
    }

    public function testGetItemDelegates(): void {
        $pool = $this->createMockPool();
        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);
        $requestedItem = $this->createMockItem('mykey', 'value', true);

        $pool->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);
        $pool->method('getItem')
            ->with('mykey')
            ->willReturn($requestedItem);

        $monitor = new MonitorCacheKeys($pool);
        self::assertSame($requestedItem, $monitor->getItem('mykey'));
    }

    public function testGetItemsDelegates(): void {
        $pool = $this->createMockPool();
        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);

        $pool->method('getItems')
            ->willReturnCallback(function ($keys) {
                return array_map(fn($k) => $this->createMockItem($k, null, true), $keys);
            });

        $monitor = new MonitorCacheKeys($pool);
        $items = iterator_to_array($monitor->getItems(['a', 'b']));
        self::assertCount(2, $items);
    }

    public function testHasItemDelegates(): void {
        $pool = $this->createMockPool();
        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);

        $pool->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);
        $pool->method('hasItem')
            ->with('mykey')
            ->willReturn(true);

        $monitor = new MonitorCacheKeys($pool);
        self::assertTrue($monitor->hasItem('mykey'));
    }

    public function testClearWhenNotEmpty(): void {
        $pool = $this->createMockPool();
        $keyListItem = $this->createMockItem('__key_list', ['key1' => true], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);

        $pool->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);
        $pool->method('getItem')
            ->with('__key_list')
            ->willReturn($keyListItem);
        $pool->expects(self::once())->method('clear')->willReturn(true);
        $pool->expects(self::exactly(2))->method('saveDeferred')->willReturn(true);
        $pool->expects(self::once())->method('commit')->willReturn(true);

        $monitor = new MonitorCacheKeys($pool);
        self::assertTrue($monitor->clear());
    }

    public function testClearWhenEmpty(): void {
        $pool = $this->createMockPool();
        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);

        $pool->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);
        $pool->method('getItem')
            ->with('__key_list')
            ->willReturn($keyListItem);
        $pool->expects(self::never())->method('clear');

        $monitor = new MonitorCacheKeys($pool);
        self::assertTrue($monitor->clear());
    }

    public function testDeleteItemUpdatesKeyList(): void {
        $pool = $this->createMockPool();
        $keyListItem = $this->createMockItem('__key_list', ['mykey' => true], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);

        $pool->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);
        $pool->method('getItem')
            ->willReturnCallback(function ($key) use ($keyListItem, $changeListItem) {
                return match ($key) {
                    '__key_list' => $keyListItem,
                    '__chg_list' => $changeListItem,
                    default => $this->createMockItem($key, null, false),
                };
            });
        $pool->expects(self::once())->method('deleteItem')->with('mykey')->willReturn(true);
        $pool->expects(self::exactly(2))->method('saveDeferred')->willReturn(true);
        $pool->expects(self::once())->method('commit')->willReturn(true);

        $monitor = new MonitorCacheKeys($pool);
        self::assertTrue($monitor->deleteItem('mykey'));
    }

    public function testDeleteItemThrowsOnPrivateKey(): void {
        $pool = $this->createMockPool();
        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);

        $pool->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);

        $monitor = new MonitorCacheKeys($pool);
        $this->expectException(OutOfBoundsException::class);
        $monitor->deleteItem('__key_list');
    }

    public function testDeleteItemsUpdatesKeyList(): void {
        $pool = $this->createMockPool();
        $keyListItem = $this->createMockItem('__key_list', ['a' => true, 'b' => true], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);

        $pool->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);
        $pool->method('getItem')
            ->with('__key_list')
            ->willReturn($keyListItem);
        $pool->expects(self::once())->method('deleteItems')->with(['a', 'b'])->willReturn(true);
        $pool->expects(self::once())->method('saveDeferred')->willReturn(true);
        $pool->expects(self::once())->method('commit')->willReturn(true);

        $monitor = new MonitorCacheKeys($pool);
        self::assertTrue($monitor->deleteItems(['a', 'b']));
    }

    public function testDeleteItemsThrowsOnPrivateKey(): void {
        $pool = $this->createMockPool();
        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);

        $pool->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);

        $monitor = new MonitorCacheKeys($pool);
        $this->expectException(OutOfBoundsException::class);
        $monitor->deleteItems(['a', '__chg_list']);
    }

    public function testSaveUpdatesKeyList(): void {
        $pool = $this->createMockPool();
        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);
        $newItem = $this->createMockItem('newkey', 'value', true);

        $pool->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);
        $pool->method('getItem')
            ->willReturnCallback(function ($key) use ($keyListItem, $changeListItem) {
                return match ($key) {
                    '__key_list' => $keyListItem,
                    '__chg_list' => $changeListItem,
                    default => $this->createMockItem($key, null, false),
                };
            });
        $pool->expects(self::once())->method('save')->with($newItem)->willReturn(true);
        $pool->expects(self::exactly(2))->method('saveDeferred')->willReturn(true);
        $pool->expects(self::once())->method('commit')->willReturn(true);

        $monitor = new MonitorCacheKeys($pool);
        self::assertTrue($monitor->save($newItem));
    }

    public function testSaveThrowsOnPrivateKey(): void {
        $pool = $this->createMockPool();
        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);
        $badItem = $this->createMockItem('__key_list', 'value', true);

        $pool->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);

        $monitor = new MonitorCacheKeys($pool);
        $this->expectException(OutOfBoundsException::class);
        $monitor->save($badItem);
    }

    public function testSaveDeferredUpdatesKeyList(): void {
        $pool = $this->createMockPool();
        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);
        $newItem = $this->createMockItem('newkey', 'value', true);

        $pool->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);
        $pool->method('getItem')
            ->willReturnCallback(function ($key) use ($keyListItem, $changeListItem) {
                return match ($key) {
                    '__key_list' => $keyListItem,
                    '__chg_list' => $changeListItem,
                    default => $this->createMockItem($key, null, false),
                };
            });
        $pool->expects(self::once())->method('saveDeferred')->with($newItem)->willReturn(true);
        $pool->expects(self::exactly(2))->method('saveDeferred')->willReturn(true);
        $pool->expects(self::once())->method('commit')->willReturn(true);

        $monitor = new MonitorCacheKeys($pool);
        self::assertTrue($monitor->saveDeferred($newItem));
    }

    public function testCommitDelegates(): void {
        $pool = $this->createMockPool();
        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);

        $pool->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);
        $pool->expects(self::once())->method('commit')->willReturn(true);

        $monitor = new MonitorCacheKeys($pool);
        self::assertTrue($monitor->commit());
    }
}
