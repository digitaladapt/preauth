<?php

declare(strict_types=1);

namespace App\Listener;

use App\Service\DomainInterface;
use App\Trait\CookieNameTrait;
use App\Trait\HasLoggerTrait;
use App\Trait\StringTrait;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

final readonly class AcceptListener
{
    use CookieNameTrait;
    use HasLoggerTrait;
    use StringTrait;

    public function __construct(
        private CacheItemPoolInterface $sessionCache,
        private DomainInterface        $domainManager,
    ) {
    }

    /** @throws InvalidArgumentException */
    #[AsEventListener(priority: 99)]
    public function onKernelRequest(RequestEvent $event): void
    {
        /* check if they sent the correct preauth cookie */
        $cookieName = $this->domainManager->authBase() ? $this->authCookieName() : $this->cookieName();
        if ($event->getRequest()->cookies->has($cookieName)) {
            $cookie = $event->getRequest()->cookies->get($cookieName);
            $cookieKey = $this->makeCacheKey("cookie_$cookie");
            if ($cookie && $this->sessionCache->hasItem($cookieKey)) {
                /* cookie sent corresponds to valid existing session */
                $id = $this->sessionCache->getItem($cookieKey)->get();
                $this->logger->debug("has valid cookie-session: $id");
                $event->setResponse(new Response("hi $id", headers: [
                    'Content-Type' => 'text/plain',
                    'Remote-User'  => $id,
                ]));
            }
        }
    }
}
