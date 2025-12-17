<?php
declare(strict_types=1);

namespace App\Listener;

use App\ConfigBag;
use App\Utilities;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class RejectListener {
    public function __construct(
        private Utilities              $utilities,
        private CacheItemPoolInterface $requestCache,
        private ConfigBag              $config,
        private LoggerInterface        $logger,
        private Environment            $twig,
    ) {}

    #[AsEventListener]
    public function onKernelRequest(RequestEvent $event): void {
        $ipKey = $this->utilities->makeCacheKey("ip_{$event->getRequest()->getClientIp()}");

        /* check if they have made too many failed login attempts */
        $failuresItem = $this->requestCache->getItem($ipKey);
        if ($failuresItem->isHit()) {
            $failures = $failuresItem->get();
            if (count($failures) >= $this->config->limit()) {
                $this->logger->debug("already blocked: {$event->getRequest()->getClientIp()}");
                $html = $this->twig->render('error.html.twig', [
                    'base_domain' => $this->utilities->baseDomain($event->getRequest()->getHost()),
                ]);
                $event->setResponse(new Response($html, status: $this->config->teapot()
                    ? Response::HTTP_I_AM_A_TEAPOT : Response::HTTP_TOO_MANY_REQUESTS
                ));
            }
        }
    }
}
