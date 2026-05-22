<?php
declare(strict_types=1);

namespace App\Listener;

use App\Trait\CookieNameTrait;
use App\Trait\HasLoggerTrait;
use App\Trait\StringTrait;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

final readonly class AcceptListener {
    use CookieNameTrait;
    use HasLoggerTrait;
    use StringTrait;

    public function __construct(
        private CacheItemPoolInterface $sessionCache,
    ) {}

    /** @throws InvalidArgumentException */
    #[AsEventListener(priority: 99)]
    public function onKernelRequest(RequestEvent $event): void {
        /* check if they sent either preauth cookie */
        if ($event->getRequest()->cookies->has($this->cookieName()) ||
            $event->getRequest()->cookies->has($this->authCookieName())
        ) {
            $cookie = $event->getRequest()->cookies->get($this->cookieName());
            if ( ! $cookie) {
                /* fallback to the auth‑subdomain cookie if the host cookie is not present */
                $cookie = $event->getRequest()->cookies->get($this->authCookieName());
            }
            $cookieKey = $this->makeCacheKey("cookie_$cookie");
            if ($cookie && $this->sessionCache->hasItem($cookieKey)) {
                /* cookie sent corresponds to valid existing session */
                $id = $this->sessionCache->getItem($cookieKey)->get();
                $this->logger->debug("has valid cookie-session: $id");
                $event->setResponse(new Response("hi $id",
                    headers: ['Content-Type' => 'text/plain']
                ));
            }
        }
    }
}
