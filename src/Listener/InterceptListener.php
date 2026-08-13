<?php

declare(strict_types=1);

namespace App\Listener;

use App\ConfigBag;
use App\Service\DomainInterface;
use App\Trait\CookieNameTrait;
use App\Trait\HasLoggerTrait;
use App\Trait\MakeNonceTrait;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

final readonly class InterceptListener
{
    use CookieNameTrait;
    use HasLoggerTrait;
    use MakeNonceTrait;

    public function __construct(
        private ConfigBag       $config,
        private DomainInterface $domainManager,
        private Environment     $twig,
    ) {
    }

    /** @throws InvalidArgumentException|RuntimeError|SyntaxError|LoaderError */
    #[AsEventListener(priority: 55)]
    public function onKernelRequest(RequestEvent $event): void
    {
        /* by this point, we know that the request we have is:
         * not already authorized, nor already rate-limited,
         * nor submitting login credentials; so redirect or present the login page now */
        if ($this->domainManager->getAuthSubdomain() !== $event->getRequest()->getHost() &&
            $this->domainManager->matchesAuth($event->getRequest()->getHost())
        ) {
            /* host matches base-domain of auth, but not on auth subdomain, redirect */
            $query = http_build_query(['return' => $event->getRequest()->getUri()]);
            $event->setResponse(new Response(
                '',
                Response::HTTP_SEE_OTHER,
                ['Location' => "https://{$this->domainManager->getAuthSubdomain()}/?$query"]
            ));
        } else {
            $this->logger->debug("presenting login page: {$event->getRequest()->getClientIp()}");
            $content = $this->twig->render('login.html.twig', [
                'nonce' => $this->makeNonce(),
                'post'  => $this->domainManager->getAuthSubdomain() === $event->getRequest()->getHost(),
            ]);
            $hasCookie = (bool) $event->getRequest()->cookies->get(
                $this->sessionCookieName($this->domainManager)
            );
            $event->setResponse($this->pruneInvalidCookie(new Response(
                $content,
                Response::HTTP_UNAUTHORIZED,
                ['Content-Type' => 'text/html']
            ), $hasCookie, $event->getRequest()->getHost()));
        }
    }

    private function pruneInvalidCookie(Response $response, bool $hasCookie, string $host): Response
    {
        if ($hasCookie) {
            $response->headers->clearCookie(
                $this->sessionCookieName($this->domainManager),
                '/',
                $this->sessionCookieDomain($this->domainManager, $host),
                true,
                true,
                Cookie::SAMESITE_STRICT
            );
        }

        return $response;
    }
}
