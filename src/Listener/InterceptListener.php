<?php
declare(strict_types=1);

namespace App\Listener;

use App\ConfigBag;
use App\Trait\MakeNonceTrait;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

final readonly class InterceptListener {
    use MakeNonceTrait;

    public function __construct(
        private CacheItemPoolInterface $requestPool,
        private ConfigBag              $config,
        private Environment            $twig,
                CacheItemPoolInterface $noncePool,
                LoggerInterface        $logger,
    ) {
        $this->logger = $logger;
        $this->noncePool = $noncePool;
    }

    /** @throws InvalidArgumentException|RuntimeError|SyntaxError|LoaderError */
    #[AsEventListener(priority: 55)]
    public function onKernelRequest(RequestEvent $event): void {
        if ($event->getRequest()) {
            /* by this point, we know that the request we have is:
             * not already authorized, nor already rate-limited,
             * nor submitting login credentials; so present the login page now */
            $this->logger->debug("presenting login page: {$event->getRequest()->getClientIp()}");
            $content = $this->twig->render('login.html.twig', [
                'nonce' => $this->makeNonce(),
            ]);
            $event->setResponse(new Response($content, Response::HTTP_UNAUTHORIZED,
                ['Content-Type' => 'text/html']
            ));
        }
    }
}
