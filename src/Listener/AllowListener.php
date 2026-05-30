<?php
declare(strict_types=1);

namespace App\Listener;

use App\ConfigBag;
use App\Trait\HasLoggerTrait;
use App\Trait\StringTrait;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

final readonly class AllowListener {
    use HasLoggerTrait;
    use StringTrait;

    public function __construct(
        private CacheItemPoolInterface $sessionCache,
        private ConfigBag              $config,
    ) {}

    /** @throws InvalidArgumentException */
    #[AsEventListener(priority: 88)]
    public function onKernelRequest(RequestEvent $event): void {
        if ($this->config->ipTtl() > 0) {
            $ipKey = $this->makeCacheKey("ip_{$event->getRequest()->getClientIp()}");
            if ($this->sessionCache->hasItem($ipKey)) {
                /* ip address corresponds to valid existing session  */
                $id = $this->sessionCache->getItem($ipKey)->get();
                $this->logger->debug("has valid ip-session: $id");
                $event->setResponse(new Response("hi $id", headers: [
                    'Content-Type' => 'text/plain',
                    'Remote-User'  => $id,
                ]));
            }
        }
    }
}
