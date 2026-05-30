<?php
declare(strict_types=1);

namespace App;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/* need autoconfigure so we get it from the service container in Kernel->boot() */
#[Autoconfigure(public: true)]
final readonly class PersistCache {
    private MonitorCacheKeys $sessionCache;
    private MonitorCacheKeys $sessionStorage;

    /** @throws InvalidArgumentException */
    public function __construct(
        CacheItemPoolInterface $sessionCache,
        CacheItemPoolInterface $sessionStorage,
    ) {
        $this->sessionCache   = new MonitorCacheKeys($sessionCache);
        $this->sessionStorage = new MonitorCacheKeys($sessionStorage);
    }

    /** @throws InvalidArgumentException */
    public function boot(): void {
        /* the caches are considered warm as soon as they are not empty */
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
        /* we only need to persist the changes made to the cache (if any) */
        $changes = $this->sessionCache->getChanges();
        if ($changes) {
            $this->sessionCache->markClean();
            $items = $this->sessionCache->getItems(array_keys($changes));
            foreach ($items as $item) {
                if (($changes[$item->getKey()] ?? null) === MonitorCacheKeys::REMOVED) {
                    $this->sessionStorage->deleteItem($item->getKey());
                } else {
                    $this->sessionStorage->saveDeferred($item);
                }
            }
            $this->sessionStorage->commit();
        }
    }
}
