<?php
declare(strict_types=1);

namespace App\Listener;

use App\ConfigBag;
use App\Trait\StringTrait;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

final readonly class RejectListener {
    use StringTrait;

    public function __construct(
        private CacheItemPoolInterface $requestPool,
        private ConfigBag              $config,
        private Environment            $twig,
        private LoggerInterface        $logger,
    ) {}

    /** @throws SyntaxError|InvalidArgumentException|RuntimeError|LoaderError */
    #[AsEventListener(priority: 77)]
    public function onKernelRequest(RequestEvent $event): void {
        $ipKey = $this->makeCacheKey("ip_{$event->getRequest()->getClientIp()}");

        /* check if they have made too many failed login attempts */
        $failuresItem = $this->requestPool->getItem($ipKey);
        if ($failuresItem->isHit()) {
            $failures = $failuresItem->get() ?? [];
            if (count($failures) >= $this->config->limit()) {
                $this->logger->debug("already blocked: {$event->getRequest()->getClientIp()}");
                $html = $this->twig->render('error.html.twig');
                $event->setResponse(new Response($html, ($this->config->teapot()
                    ? Response::HTTP_I_AM_A_TEAPOT : Response::HTTP_TOO_MANY_REQUESTS),
                    ['Content-Type' => 'text/html']
                ));
            }
        }
    }
}
