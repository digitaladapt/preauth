<?php

declare(strict_types=1);

namespace App\Listener;

use App\ConfigBag;
use App\Service\DomainInterface;
use App\Trait\CookieNameTrait;
use App\Trait\HasLoggerTrait;
use App\Trait\StringTrait;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

final readonly class AcceptListener
{
    use CookieNameTrait;
    use HasLoggerTrait;
    use StringTrait;

    public function __construct(
        private CacheItemPoolInterface $sessionCache,
        private DomainInterface        $domainManager,
        private ConfigBag              $config,
    ) {
    }

    #[AsEventListener(priority: 99)]
    public function onKernelRequest(RequestEvent $event): void
    {
        /* check if they sent the correct preauth cookie */
        $cookieName = $this->sessionCookieName($this->domainManager);
        if (! $event->getRequest()->cookies->has($cookieName)) {
            return;
        }

        $cookie = $event->getRequest()->cookies->get($cookieName);
        $cookieKey = $this->makeCacheKey("cookie_$cookie");

        try {
            if (! $cookie || ! $this->sessionCache->hasItem($cookieKey)) {
                return;
            }

            /* cookie sent corresponds to valid existing session */
            $item = $this->sessionCache->getItem($cookieKey);
            if (! $item->isHit()) {
                /* race condition: item was removed between hasItem and getItem */
                return;
            }

            $id = $item->get();
            $this->logger->debug("has valid cookie-session: $id");
            $event->setResponse($this->authSuccessResponse($id, $this->config));
        } catch (InvalidArgumentException $e) {
            /* cache failure — fail closed (don't authenticate) */
            $this->logger->error("cache error in AcceptListener: {$e->getMessage()}");
        }
    }
}
