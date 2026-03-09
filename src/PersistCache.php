<?php
declare(strict_types=1);

namespace App;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(public: true)]
final readonly class PersistCache {
    private MonitorCacheKeys $requestCache;
    private MonitorCacheKeys $requestStorage;
    private MonitorCacheKeys $sessionCache;
    private MonitorCacheKeys $sessionStorage;

    /** @throws InvalidArgumentException */
    public function __construct(
        CacheItemPoolInterface $requestCache,
        CacheItemPoolInterface $requestStorage,
        CacheItemPoolInterface $sessionCache,
        CacheItemPoolInterface $sessionStorage,
    ) {
        $this->requestCache        = new MonitorCacheKeys($requestCache);
        $this->requestStorage = new MonitorCacheKeys($requestStorage);
        $this->sessionCache        = new MonitorCacheKeys($sessionCache);
        $this->sessionStorage = new MonitorCacheKeys($sessionStorage);
    }

    /** @throws InvalidArgumentException */
    public function boot(): void {
        /* the caches are considered warm as soon as they are not empty */
        if (empty($this->requestCache->getKeys())) {
            $items = $this->requestStorage->getItems($this->requestStorage->getKeys());
            foreach ($items as $item) {
                $this->requestCache->saveDeferred($item);
            }
            $this->requestCache->markClean();
            $this->requestCache->commit();
        }

        if (empty($this->sessionCache->getKeys())) {
            $items = $this->sessionStorage->getItems($this->sessionStorage->getKeys());
            foreach ($items as $item) {
                $this->sessionCache->saveDeferred($item);
            }
            $this->sessionCache->markClean();
            $this->sessionCache->commit();
        }
    }

    /** @throws InvalidArgumentException */
    public function persist(): void {
        /* we only need to persist the caches if they contain changes */
        if ($this->requestCache->isDirty()) {
            $this->requestCache->markClean();
            $items = $this->requestCache->getItems($this->requestCache->getKeys());
            $this->requestStorage->clear();
            foreach ($items as $item) {
                $this->requestStorage->saveDeferred($item);
            }
            $this->requestStorage->commit();
        }

        if ($this->sessionCache->isDirty()) {
            $this->sessionCache->markClean();
            $items = $this->sessionCache->getItems($this->sessionCache->getKeys());
            $this->sessionStorage->clear();
            foreach ($items as $item) {
                $this->sessionStorage->saveDeferred($item);
            }
            $this->sessionStorage->commit();
        }
    }
}
