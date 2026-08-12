<?php

declare(strict_types=1);

namespace App\Listener;

use App\ConfigBag;
use App\Trait\HasLoggerTrait;
use App\Trait\StringTrait;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

final readonly class AllowListener
{
    use HasLoggerTrait;
    use StringTrait;

    public function __construct(
        private CacheItemPoolInterface $sessionCache,
        private ConfigBag              $config,
    ) {
    }

    #[AsEventListener(priority: 88)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if ($this->config->ipTtl() <= 0) {
            return;
        }

        $ipKey = $this->makeCacheKey("ip_{$event->getRequest()->getClientIp()}");

        try {
            if (! $this->sessionCache->hasItem($ipKey)) {
                return;
            }

            /* ip address corresponds to valid existing session */
            $item = $this->sessionCache->getItem($ipKey);
            if (! $item->isHit()) {
                /* race condition: item was removed between hasItem and getItem */
                return;
            }

            $id = $item->get();
            $this->logger->debug("has valid ip-session: $id");
            $event->setResponse($this->authSuccessResponse($id, $this->config));
        } catch (InvalidArgumentException $e) {
            /* cache failure — fail closed (don't authenticate) */
            $this->logger->error("cache error in AllowListener: {$e->getMessage()}");
        }
    }
}
