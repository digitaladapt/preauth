<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\MonitorCacheKeys;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class MonitorCacheKeysTest extends TestCase
{
    private function wrap(?ArrayAdapter $pool = null): MonitorCacheKeys
    {
        $pool ??= new ArrayAdapter();
        return new MonitorCacheKeys($pool);
    }

    public function testConstructorInitializesEmptyPool(): void
    {
        $monitor = $this->wrap();

        self::assertSame([], $monitor->getKeys());
        self::assertSame([], $monitor->getChanges());
    }

    public function testSaveAddsKeyAndTracksChange(): void
    {
        $monitor = $this->wrap();
        $item = $monitor->getItem('alpha');
        $item->set('value');
        $monitor->save($item);

        self::assertSame(['alpha'], $monitor->getKeys());
        self::assertSame(['alpha' => MonitorCacheKeys::UPDATED], $monitor->getChanges());
    }

    public function testSaveDeferredThenCommitAddsKey(): void
    {
        $monitor = $this->wrap();
        $item = $monitor->getItem('beta');
        $item->set('value');
        $monitor->saveDeferred($item);

        // saveDeferred calls update() which commits immediately
        self::assertSame(['beta'], $monitor->getKeys());
        self::assertSame(['beta' => MonitorCacheKeys::UPDATED], $monitor->getChanges());
    }

    public function testGetItemReturnsUnderlyingItem(): void
    {
        $monitor = $this->wrap();
        $item = $monitor->getItem('mykey');
        $item->set('data');
        $monitor->save($item);

        $fetched = $monitor->getItem('mykey');
        self::assertTrue($fetched->isHit());
        self::assertSame('data', $fetched->get());
    }

    public function testGetItemsReturnsMultipleItems(): void
    {
        $monitor = $this->wrap();
        $a = $monitor->getItem('a');
        $a->set(1);
        $monitor->save($a);
        $b = $monitor->getItem('b');
        $b->set(2);
        $monitor->save($b);

        $items = $monitor->getItems(['a', 'b']);
        $keys = [];
        foreach ($items as $key => $item) {
            $keys[$key] = $item->get();
        }
        self::assertSame(['a' => 1, 'b' => 2], $keys);
    }

    public function testHasItemReturnsTrueForExistingKey(): void
    {
        $monitor = $this->wrap();
        $item = $monitor->getItem('exists');
        $item->set('v');
        $monitor->save($item);

        self::assertTrue($monitor->hasItem('exists'));
        self::assertFalse($monitor->hasItem('missing'));
    }

    public function testDeleteItemRemovesKeyAndTracksRemoval(): void
    {
        $monitor = $this->wrap();
        $item = $monitor->getItem('doomed');
        $item->set('v');
        $monitor->save($item);

        $monitor->deleteItem('doomed');

        self::assertSame([], $monitor->getKeys());
        self::assertSame(['doomed' => MonitorCacheKeys::REMOVED], $monitor->getChanges());
        self::assertFalse($monitor->hasItem('doomed'));
    }

    public function testDeleteItemOnMissingKeyIsNoop(): void
    {
        $monitor = $this->wrap();

        $result = $monitor->deleteItem('nonexistent');

        self::assertTrue($result);
        self::assertSame([], $monitor->getKeys());
    }

    public function testDeleteItemsRemovesMultipleKeys(): void
    {
        $monitor = $this->wrap();
        foreach (['x', 'y', 'z'] as $key) {
            $item = $monitor->getItem($key);
            $item->set($key);
            $monitor->save($item);
        }

        $monitor->deleteItems(['x', 'y']);

        self::assertSame(['z'], $monitor->getKeys());
        $changes = $monitor->getChanges();
        self::assertSame(MonitorCacheKeys::REMOVED, $changes['x']);
        self::assertSame(MonitorCacheKeys::REMOVED, $changes['y']);
    }

    public function testDeleteItemsWithMissingKeysStillReturnsTrue(): void
    {
        $monitor = $this->wrap();

        $result = $monitor->deleteItems(['ghost1', 'ghost2']);

        self::assertTrue($result);
    }

    public function testClearWipesPoolWhenNotEmpty(): void
    {
        $monitor = $this->wrap();
        $item = $monitor->getItem('keep');
        $item->set('v');
        $monitor->save($item);

        $result = $monitor->clear();

        self::assertTrue($result);
        self::assertSame([], $monitor->getKeys());
    }

    public function testClearIsNoopWhenEmpty(): void
    {
        $monitor = $this->wrap();

        $result = $monitor->clear();

        self::assertTrue($result);
    }

    public function testMarkCleanResetsChangeList(): void
    {
        $monitor = $this->wrap();
        $item = $monitor->getItem('temp');
        $item->set('v');
        $monitor->save($item);

        self::assertNotEmpty($monitor->getChanges());

        $monitor->markClean();

        self::assertSame([], $monitor->getChanges());
        self::assertSame(['temp'], $monitor->getKeys());
    }

    public function testCommitPassesThrough(): void
    {
        $monitor = $this->wrap();

        self::assertTrue($monitor->commit());
    }

    public function testSaveKeyListThrowsOutOfBoundsException(): void
    {
        $monitor = $this->wrap();
        $item = $monitor->getItem('__key_list');

        $this->expectException(OutOfBoundsException::class);
        $monitor->save($item);
    }

    public function testSaveChangeListThrowsOutOfBoundsException(): void
    {
        $monitor = $this->wrap();
        $item = $monitor->getItem('__chg_list');

        $this->expectException(OutOfBoundsException::class);
        $monitor->save($item);
    }

    public function testDeleteKeyListThrowsOutOfBoundsException(): void
    {
        $monitor = $this->wrap();

        $this->expectException(OutOfBoundsException::class);
        $monitor->deleteItem('__key_list');
    }

    public function testDeleteChangeListThrowsOutOfBoundsException(): void
    {
        $monitor = $this->wrap();

        $this->expectException(OutOfBoundsException::class);
        $monitor->deleteItem('__chg_list');
    }

    public function testDeleteItemsWithKeyListThrowsOutOfBoundsException(): void
    {
        $monitor = $this->wrap();

        $this->expectException(OutOfBoundsException::class);
        $monitor->deleteItems(['safe', '__key_list']);
    }

    public function testDeleteItemsWithChangeListThrowsOutOfBoundsException(): void
    {
        $monitor = $this->wrap();

        $this->expectException(OutOfBoundsException::class);
        $monitor->deleteItems(['__chg_list']);
    }

    public function testSaveDeferredOnKeyListThrowsOutOfBoundsException(): void
    {
        $monitor = $this->wrap();
        $item = $monitor->getItem('safe');
        $item->set('value');

        // getItem returns the real item, but saveDeferred calls update() which
        // validates the key — so we need to get the __key_list item and try to save it
        $keyListItem = $monitor->getItem('__key_list');

        $this->expectException(OutOfBoundsException::class);
        $monitor->saveDeferred($keyListItem);
    }

    public function testSaveDeferredOnChangeListThrowsOutOfBoundsException(): void
    {
        $monitor = $this->wrap();
        $changeListItem = $monitor->getItem('__chg_list');

        $this->expectException(OutOfBoundsException::class);
        $monitor->saveDeferred($changeListItem);
    }

    public function testGetKeysReturnsEmptyArrayWhenKeyListMissing(): void
    {
        // If the underlying pool loses its key list, getKeys should return []
        $pool = new ArrayAdapter();
        $monitor = new MonitorCacheKeys($pool);

        $item = $monitor->getItem('alpha');
        $item->set('value');
        $monitor->save($item);

        // delete the key list directly from the underlying pool
        $pool->deleteItem('__key_list');

        $monitor2 = new MonitorCacheKeys($pool);
        // the constructor will re-initialize since __key_list is missing
        // but getKeys on the new monitor should be empty
        self::assertSame([], $monitor2->getKeys());
    }

    public function testDeleteItemReturnsTrueForExistingKey(): void
    {
        $monitor = $this->wrap();
        $item = $monitor->getItem('to-delete');
        $item->set('value');
        $monitor->save($item);

        self::assertTrue($monitor->deleteItem('to-delete'));
        self::assertNotContains('to-delete', $monitor->getKeys());
    }

    public function testDeleteItemsReturnsTrue(): void
    {
        $monitor = $this->wrap();
        foreach (['a', 'b', 'c'] as $key) {
            $item = $monitor->getItem($key);
            $item->set('value');
            $monitor->save($item);
        }

        self::assertTrue($monitor->deleteItems(['a', 'b', 'c']));
        self::assertSame([], $monitor->getKeys());
    }
}
