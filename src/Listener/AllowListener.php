<?php
declare(strict_types=1);

namespace App\Listener;

use App\ConfigBag;
use App\Trait\StringTrait;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

final readonly class AllowListener {
    use StringTrait;

    public function __construct(
        private CacheItemPoolInterface $sessionPool,
        private ConfigBag              $config,
        private LoggerInterface        $logger,
    ) {}

    /** @throws InvalidArgumentException */
    #[AsEventListener(priority: 88)]
    public function onKernelRequest(RequestEvent $event): void {
        if ($this->config->ipTtl() > 0) {
            $ipKey = $this->makeCacheKey("ip_{$event->getRequest()->getClientIp()}");
            if ($this->sessionPool->hasItem($ipKey)) {
                /* ip address corresponds to valid existing session  */
                $id = $this->sessionPool->getItem($ipKey)->get();
                $this->logger->debug("has valid ip-session: $id");
                $event->setResponse(new Response("hi $id",
                    headers: ['Content-Type' => 'text/plain']
                ));
            }
        }
    }
}
