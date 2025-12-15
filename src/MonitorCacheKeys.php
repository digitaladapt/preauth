<?php
declare(strict_types=1);

namespace App;

use OutOfBoundsException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;

/* We must not store the key-list item or values within this object,
 * because it can change from outside this object instance. */
class MonitorCacheKeys implements CacheItemPoolInterface {
    private const KEY_LIST = '__key_list';
    private const IS_DIRTY = '__is_dirty';

    private CacheItemPoolInterface $cache;

    /** @throws InvalidArgumentException we sanitize cache keys, to prevent this */
    public function __construct(CacheItemPoolInterface $cache) {
        $this->cache = $cache;
        $items = $cache->getItems([self::KEY_LIST, self::IS_DIRTY]);
        foreach ($items as $item) {
            if ( ! $item->isHit()) {
                $this->initialize();
                break;
            }
        }
    }

    /** @throws InvalidArgumentException we sanitize cache keys, to prevent this */
    private function initialize(): void {
        $keyList = $this->cache->getItem(self::KEY_LIST);
        $isDirty = $this->cache->getItem(self::IS_DIRTY);
        $keyList->set([]);
        $isDirty->set(false);
        $this->cache->saveDeferred($keyList);
        $this->cache->saveDeferred($isDirty);
        $this->cache->commit();
    }

    /** @throws InvalidArgumentException we sanitize cache keys, to prevent this */
    public function getKeys(): array {
        $keyList = $this->cache->getItem(self::KEY_LIST);
        return array_keys($keyList->get() ?? []);
    }

    /** @throws InvalidArgumentException we sanitize cache keys, to prevent this */
    public function isDirty(): bool {
        $isDirty = $this->cache->getItem(self::IS_DIRTY);
        return $isDirty->get() ?? false;
    }

    /** @throws InvalidArgumentException we sanitize cache keys, to prevent this */
    public function markClean(): void {
        $isDirty = $this->cache->getItem(self::IS_DIRTY);
        $isDirty->set(false);
        $this->cache->save($isDirty);
    }

    public function getItem(string $key): CacheItemInterface {
        return $this->cache->getItem($key);
    }

    public function getItems(array $keys = []): iterable {
        return $this->cache->getItems($keys);
    }

    public function hasItem(string $key): bool {
        return $this->cache->hasItem($key);
    }

    /** @throws InvalidArgumentException we sanitize cache keys, to prevent this */
    public function clear(): bool {
        /* only bother clearing the pool if it is not empty */
        if ( ! empty($this->getKeys())) {
            $response = $this->cache->clear();

            $this->initialize();
            return $response;
        }
        return true;
    }

    public function deleteItem(string $key): bool {
        if ($key === self::KEY_LIST || $key === self::IS_DIRTY) {
            throw new OutOfBoundsException(
                'Can not delete the private key list or is dirty flag'
            );
        }
        $keyList = $this->cache->getItem(self::KEY_LIST);
        $isDirty = $this->cache->getItem(self::IS_DIRTY);
        $keyValues = $keyList->get();
        if (isset($keyValues[$key])) {
            unset($keyValues[$key]);
            $keyList->set($keyValues);
            $isDirty->set(true);
            $this->cache->saveDeferred($keyList);
            $this->cache->saveDeferred($isDirty);
            $this->cache->commit();
        }

        return $this->cache->deleteItem($key);
    }

    public function deleteItems(array $keys): bool {
        if (in_array(self::KEY_LIST, $keys, true) ||
            in_array(self::IS_DIRTY, $keys, true)
        ) {
            throw new OutOfBoundsException(
                'Can not delete the private key list or is dirty flag'
            );
        }
        $keyList = $this->cache->getItem(self::KEY_LIST);
        $isDirty = $this->cache->getItem(self::IS_DIRTY);
        $keyValues = $keyList->get();
        foreach ($keys as $key) {
            if (isset($keyValues[$key])) {
                unset($keyValues[$key]);
                $isDirty->set(true);
            }
        }
        $keyList->set($keyValues);
        $this->cache->saveDeferred($keyList);
        $this->cache->saveDeferred($isDirty);
        $this->cache->commit();

        return $this->cache->deleteItems($keys);
    }

    /** @throws InvalidArgumentException we sanitize cache keys, to prevent this */
    public function save(CacheItemInterface $item): bool {
        $this->update($item);
        return $this->cache->save($item);
    }

    /** @throws InvalidArgumentException we sanitize cache keys, to prevent this */
    public function saveDeferred(CacheItemInterface $item): bool {
        $this->update($item);
        return $this->cache->saveDeferred($item);
    }

    /** @throws InvalidArgumentException we sanitize cache keys, to prevent this */
    private function update(CacheItemInterface $item) {
        if ($item->getKey() === self::KEY_LIST || $item->getKey() === self::IS_DIRTY) {
            throw new OutOfBoundsException(
                'Can not alter the private key list or is dirty flag'
            );
        }
        $keyList = $this->cache->getItem(self::KEY_LIST);
        $isDirty = $this->cache->getItem(self::IS_DIRTY);
        $keyValues = $keyList->get();
        $keyValues[$item->getKey()] = true;
        $keyList->set($keyValues);
        $isDirty->set(true);
        $this->cache->saveDeferred($keyList);
        $this->cache->saveDeferred($isDirty);
        $this->cache->commit();
    }

    public function commit(): bool {
        return $this->cache->commit();
    }
}
