<?php
declare(strict_types=1);

namespace App\Listener;

use App\ConfigBag;
use App\Trait\HasLoggerTrait;
use App\Trait\StringTrait;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

final readonly class RejectListener {
    use HasLoggerTrait;
    use StringTrait;

    private RateLimiterFactoryInterface $rateLimiter;

    public function __construct(
        private                    ConfigBag                   $config,
        private                    Environment                 $twig,
        #[Target('login_limiter')] RateLimiterFactoryInterface $rateLimiter,
    ) {
        $this->rateLimiter = $rateLimiter;
    }

    /** @throws SyntaxError|RuntimeError|LoaderError */
    #[AsEventListener(priority: 77)]
    public function onKernelRequest(RequestEvent $event): void {
        /* check if they have made too many failed login attempts */
        $limiter = $this->rateLimiter->create($event->getRequest()->getClientIp());
        if ($limiter->consume(0)->getRemainingTokens() < 1) {
            $this->logger->debug("already blocked: {$event->getRequest()->getClientIp()}");
            $html = $this->twig->render('error.html.twig');
            $event->setResponse(new Response($html, ($this->config->teapot()
                ? Response::HTTP_I_AM_A_TEAPOT : Response::HTTP_TOO_MANY_REQUESTS),
                ['Content-Type' => 'text/html']
            ));
        }
    }
}
