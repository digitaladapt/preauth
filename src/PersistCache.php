<?php
declare(strict_types=1);

namespace App;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(public: true)]
class PersistCache {
    private MonitorCacheKeys $requestCache;
    private MonitorCacheKeys $persistRequestCache;
    private MonitorCacheKeys $sessionCache;
    private MonitorCacheKeys $persistSessionCache;

    /** @throws InvalidArgumentException we sanitize cache keys, to prevent this */
    public function __construct(
        CacheItemPoolInterface $requestCache,
        CacheItemPoolInterface $persistRequestCache,
        CacheItemPoolInterface $sessionCache,
        CacheItemPoolInterface $persistSessionCache
    ) {
        $this->requestCache        = new MonitorCacheKeys($requestCache);
        $this->persistRequestCache = new MonitorCacheKeys($persistRequestCache);
        $this->sessionCache        = new MonitorCacheKeys($sessionCache);
        $this->persistSessionCache = new MonitorCacheKeys($persistSessionCache);
    }

    /** @throws InvalidArgumentException we sanitize cache keys, to prevent this */
    public function boot(): void {
        /* the caches are considered warm as soon as they are not empty */
        if (empty($this->requestCache->getKeys())) {
            $items = $this->persistRequestCache->getItems($this->persistRequestCache->getKeys());
            foreach ($items as $item) {
                $this->requestCache->saveDeferred($item);
            }
            $this->requestCache->markClean();
            $this->requestCache->commit();
        }

        if (empty($this->sessionCache->getKeys())) {
            $items = $this->persistSessionCache->getItems($this->persistSessionCache->getKeys());
            foreach ($items as $item) {
                $this->sessionCache->saveDeferred($item);
            }
            $this->sessionCache->markClean();
            $this->sessionCache->commit();
        }
    }

    /** @throws InvalidArgumentException we sanitize cache keys, to prevent this */
    public function persist(): void {
        /* we only need to persist the caches if they contain changes */
        if ($this->requestCache->isDirty()) {
            $this->requestCache->markClean();
            $items = $this->requestCache->getItems($this->requestCache->getKeys());
            $this->persistRequestCache->clear();
            foreach ($items as $item) {
                $this->persistRequestCache->saveDeferred($item);
            }
            $this->persistRequestCache->commit();
        }

        if ($this->sessionCache->isDirty()) {
            $this->sessionCache->markClean();
            $items = $this->sessionCache->getItems($this->sessionCache->getKeys());
            $this->persistSessionCache->clear();
            foreach ($items as $item) {
                $this->persistSessionCache->saveDeferred($item);
            }
            $this->persistSessionCache->commit();
        }
    }
}
