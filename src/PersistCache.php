<?php
declare(strict_types=1);

namespace App;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(public: true)]
final readonly class PersistCache {
    private MonitorCacheKeys $requestPool;
    private MonitorCacheKeys $persistRequestPool;
    private MonitorCacheKeys $sessionPool;
    private MonitorCacheKeys $persistSessionPool;

    /** @throws InvalidArgumentException */
    public function __construct(
        CacheItemPoolInterface $requestPool,
        CacheItemPoolInterface $persistRequestPool,
        CacheItemPoolInterface $sessionPool,
        CacheItemPoolInterface $persistSessionPool
    ) {
        $this->requestPool        = new MonitorCacheKeys($requestPool);
        $this->persistRequestPool = new MonitorCacheKeys($persistRequestPool);
        $this->sessionPool        = new MonitorCacheKeys($sessionPool);
        $this->persistSessionPool = new MonitorCacheKeys($persistSessionPool);
    }

    /** @throws InvalidArgumentException */
    public function boot(): void {
        /* the caches are considered warm as soon as they are not empty */
        if (empty($this->requestPool->getKeys())) {
            $items = $this->persistRequestPool->getItems($this->persistRequestPool->getKeys());
            foreach ($items as $item) {
                $this->requestPool->saveDeferred($item);
            }
            $this->requestPool->markClean();
            $this->requestPool->commit();
        }

        if (empty($this->sessionPool->getKeys())) {
            $items = $this->persistSessionPool->getItems($this->persistSessionPool->getKeys());
            foreach ($items as $item) {
                $this->sessionPool->saveDeferred($item);
            }
            $this->sessionPool->markClean();
            $this->sessionPool->commit();
        }
    }

    /** @throws InvalidArgumentException */
    public function persist(): void {
        /* we only need to persist the caches if they contain changes */
        if ($this->requestPool->isDirty()) {
            $this->requestPool->markClean();
            $items = $this->requestPool->getItems($this->requestPool->getKeys());
            $this->persistRequestPool->clear();
            foreach ($items as $item) {
                $this->persistRequestPool->saveDeferred($item);
            }
            $this->persistRequestPool->commit();
        }

        if ($this->sessionPool->isDirty()) {
            $this->sessionPool->markClean();
            $items = $this->sessionPool->getItems($this->sessionPool->getKeys());
            $this->persistSessionPool->clear();
            foreach ($items as $item) {
                $this->persistSessionPool->saveDeferred($item);
            }
            $this->persistSessionPool->commit();
        }
    }
}
