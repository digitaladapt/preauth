<?php

declare(strict_types=1);

namespace App;

use OutOfBoundsException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;

/* we must *NOT* store the key-list item or values within this object
 * because it can change from outside this object instance */
final readonly class MonitorCacheKeys implements CacheItemPoolInterface
{
    private const string KEY_LIST = '__key_list';
    private const string CHANGE_LIST = '__chg_list';
    public const int UPDATED = 1;
    public const int REMOVED = 2;

    private CacheItemPoolInterface $cache;

    /** @throws InvalidArgumentException */
    public function __construct(CacheItemPoolInterface $cache)
    {
        $this->cache = $cache;
        $items = $cache->getItems([self::KEY_LIST, self::CHANGE_LIST]);
        foreach ($items as $item) {
            if (! $item->isHit()) {
                $this->initialize();
                break;
            }
        }
    }

    /** @throws InvalidArgumentException */
    private function initialize(): void
    {
        $keyList = $this->cache->getItem(self::KEY_LIST);
        $changeList = $this->cache->getItem(self::CHANGE_LIST);
        $keyList->set([]);
        $changeList->set([]);
        $this->cache->saveDeferred($keyList);
        $this->cache->saveDeferred($changeList);
        $this->cache->commit();
    }

    /** @throws InvalidArgumentException */
    public function getKeys(): array
    {
        $keyList = $this->cache->getItem(self::KEY_LIST);
        return array_keys($keyList->get() ?? []);
    }

    /** @throws InvalidArgumentException */
    public function getChanges(): array
    {
        $changeList = $this->cache->getItem(self::CHANGE_LIST);
        return $changeList->get() ?? [];
    }

    /** @throws InvalidArgumentException */
    public function markClean(): void
    {
        $changeList = $this->cache->getItem(self::CHANGE_LIST);
        $changeList->set([]);
        $this->cache->save($changeList);
    }

    /** @throws InvalidArgumentException */
    public function getItem(string $key): CacheItemInterface
    {
        return $this->cache->getItem($key);
    }

    /** @return CacheItemInterface[]
     *  @throws InvalidArgumentException */
    public function getItems(array $keys = []): iterable
    {
        return $this->cache->getItems($keys);
    }

    /** @throws InvalidArgumentException */
    public function hasItem(string $key): bool
    {
        return $this->cache->hasItem($key);
    }

    /** @throws InvalidArgumentException */
    public function clear(): bool
    {
        /* only bother clearing the pool if it is not empty */
        if (! empty($this->getKeys())) {
            $response = $this->cache->clear();

            $this->initialize();
            return $response;
        }
        return true;
    }

    /** @throws InvalidArgumentException */
    public function deleteItem(string $key): bool
    {
        $this->isValid($key);
        $keyList = $this->cache->getItem(self::KEY_LIST);
        $keyValues = $keyList->get();
        if (isset($keyValues[$key])) {
            unset($keyValues[$key]);
            $keyList->set($keyValues);
            $this->cache->saveDeferred($keyList);
            $this->logChange($key, MonitorCacheKeys::REMOVED);
            $this->cache->commit();
        }

        return $this->cache->deleteItem($key);
    }

    /** @throws InvalidArgumentException */
    public function deleteItems(array $keys): bool
    {
        $this->allValid($keys);
        $keyList = $this->cache->getItem(self::KEY_LIST);
        $keyValues = $keyList->get();
        foreach ($keys as $key) {
            if (isset($keyValues[$key])) {
                unset($keyValues[$key]);
                $this->logChange($key, MonitorCacheKeys::REMOVED);
            }
        }
        $keyList->set($keyValues);
        $this->cache->saveDeferred($keyList);
        $this->cache->commit();

        return $this->cache->deleteItems($keys);
    }

    /** @throws InvalidArgumentException */
    public function save(CacheItemInterface $item): bool
    {
        $this->update($item);
        return $this->cache->save($item);
    }

    /** @throws InvalidArgumentException */
    public function saveDeferred(CacheItemInterface $item): bool
    {
        $this->update($item);
        return $this->cache->saveDeferred($item);
    }

    /** @throws InvalidArgumentException */
    public function commit(): bool
    {
        return $this->cache->commit();
    }

    /** @throws InvalidArgumentException|OutOfBoundsException */
    private function update(CacheItemInterface $item): void
    {
        $this->isValid($item->getKey());
        $keyList = $this->cache->getItem(self::KEY_LIST);
        $keyValues = $keyList->get();
        $keyValues[$item->getKey()] = true;
        $keyList->set($keyValues);
        $this->logChange($item->getKey());
        $this->cache->saveDeferred($keyList);
        $this->cache->commit();
    }

    /** @throws OutOfBoundsException */
    private function isValid(string $key): void
    {
        if ($key === self::KEY_LIST || $key === self::CHANGE_LIST) {
            throw new OutOfBoundsException(
                'Can not modify the private key or change lists'
            );
        }
    }

    /** @throws OutOfBoundsException */
    private function allValid(array $keys): void
    {
        if (in_array(self::KEY_LIST, $keys, true) ||
            in_array(self::CHANGE_LIST, $keys, true)
        ) {
            throw new OutOfBoundsException(
                'Can not modify the private key or change lists'
            );
        }
    }

    /** @throws InvalidArgumentException */
    private function logChange(string $key, int $code = MonitorCacheKeys::UPDATED): void
    {
        $changeList = $this->cache->getItem(self::CHANGE_LIST);
        $changeValues = $changeList->get();
        $changeValues[$key] = $code;
        $changeList->set($changeValues);
        $this->cache->saveDeferred($changeList);
    }
}
