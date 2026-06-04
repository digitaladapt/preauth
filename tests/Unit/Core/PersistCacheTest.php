<?php
declare(strict_types=1);

namespace App\Tests\Unit\Core;

use App\MonitorCacheKeys;
use App\PersistCache;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

final class PersistCacheTest extends TestCase {
    private function createMockItem(string $key, mixed $value = null, bool $isHit = true): CacheItemInterface {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('getKey')->willReturn($key);
        $item->method('get')->willReturn($value);
        $item->method('isHit')->willReturn($isHit);
        return $item;
    }

    private function createMockPool(): CacheItemPoolInterface {
        return $this->createMock(CacheItemPoolInterface::class);
    }

    public function testBootWhenCacheIsEmpty(): void {
        $sessionCache = $this->createMockPool();
        $sessionStorage = $this->createMockPool();

        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);

        $sessionCache->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);
        $sessionCache->method('getItem')
            ->with('__key_list')
            ->willReturn($keyListItem);

        $storageKeyList = $this->createMockItem('__key_list', ['item1' => true], true);
        $storageChangeList = $this->createMockItem('__chg_list', [], true);
        $storageItem = $this->createMockItem('item1', 'value1', true);

        $sessionStorage->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$storageKeyList, $storageChangeList]);
        $sessionStorage->method('getItem')
            ->with('__key_list')
            ->willReturn($storageKeyList);
        $sessionStorage->method('getItems')
            ->with(['item1'])
            ->willReturn(['item1' => $storageItem]);

        $sessionCache->expects(self::once())->method('saveDeferred')->with($storageItem)->willReturn(true);
        $sessionCache->expects(self::once())->method('commit')->willReturn(true);

        $persistCache = new PersistCache($sessionCache, $sessionStorage);
        $persistCache->boot();
    }

    public function testBootWhenCacheIsNotEmpty(): void {
        $sessionCache = $this->createMockPool();
        $sessionStorage = $this->createMockPool();

        $keyListItem = $this->createMockItem('__key_list', ['item1' => true], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);

        $sessionCache->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);
        $sessionCache->method('getItem')
            ->with('__key_list')
            ->willReturn($keyListItem);

        $sessionStorage->expects(self::never())->method('getItems');
        $sessionCache->expects(self::never())->method('saveDeferred');

        $persistCache = new PersistCache($sessionCache, $sessionStorage);
        $persistCache->boot();
    }

    public function testPersistWithChanges(): void {
        $sessionCache = $this->createMockPool();
        $sessionStorage = $this->createMockPool();

        $keyListItem = $this->createMockItem('__key_list', ['item1' => true, 'item2' => true], true);
        $changeListItem = $this->createMockItem('__chg_list', ['item1' => MonitorCacheKeys::UPDATED, 'item2' => MonitorCacheKeys::REMOVED], true);

        $sessionCache->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);
        $sessionCache->method('getItem')
            ->willReturnCallback(function ($key) use ($keyListItem, $changeListItem) {
                return match ($key) {
                    '__key_list' => $keyListItem,
                    '__chg_list' => $changeListItem,
                    default => $this->createMockItem($key, 'value', true),
                };
            });

        $item1 = $this->createMockItem('item1', 'value1', true);
        $item2 = $this->createMockItem('item2', null, false);

        $sessionCache->method('getItems')
            ->with(['item1', 'item2'])
            ->willReturn(['item1' => $item1, 'item2' => $item2]);

        $sessionStorage->expects(self::once())->method('saveDeferred')->with($item1)->willReturn(true);
        $sessionStorage->expects(self::once())->method('deleteItem')->with('item2')->willReturn(true);
        $sessionStorage->expects(self::once())->method('commit')->willReturn(true);

        $persistCache = new PersistCache($sessionCache, $sessionStorage);
        $persistCache->persist();
    }

    public function testPersistWithNoChanges(): void {
        $sessionCache = $this->createMockPool();
        $sessionStorage = $this->createMockPool();

        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);

        $sessionCache->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);
        $sessionCache->method('getItem')
            ->with('__chg_list')
            ->willReturn($changeListItem);

        $sessionStorage->expects(self::never())->method('saveDeferred');
        $sessionStorage->expects(self::never())->method('deleteItem');
        $sessionStorage->expects(self::never())->method('commit');

        $persistCache = new PersistCache($sessionCache, $sessionStorage);
        $persistCache->persist();
    }
}
