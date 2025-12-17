<?php
declare(strict_types=1);

namespace App\Listener;

use App\ConfigBag;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpFoundation\Response;

final class AcceptListener {
    public function __construct(
        private CacheItemPoolInterface $sessionCache,
        private ConfigBag              $config,
        private LoggerInterface        $logger,
    ) {}

    #[AsEventListener(priority: 99)]
    public function onKernelRequest(RequestEvent $event): void {
        /* check if they sent the preauth cookie */
        if ($event->getRequest()->cookies->has($this->config->query('ulid'))) {
            if ($this->sessionCache->hasItem(
                'cookie_' . $event->getRequest()->cookies->get($this->config->query('ulid'))
            )) {
                /* cookie sent corresponds valid existing session */
                $id = $this->sessionCache->getItem(
                    'cookie_' . $event->getRequest()->cookies->get($this->config->query('ulid'))
                )->get();
                $this->logger->debug("has valid cookie-session: $id");
                $event->setResponse(new Response("hi $id"));
            }
        }
    }
}
