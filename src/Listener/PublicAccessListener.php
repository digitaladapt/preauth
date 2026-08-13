<?php

declare(strict_types=1);

namespace App\Listener;

use App\Service\DomainInterface;
use App\Service\PublicPathMatcherInterface;
use App\Trait\HasLoggerTrait;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

/**
 * Allows rate-limited unauthenticated access to configured public paths.
 *
 * Runs at priority 84 — after AcceptListener (99) and AllowListener (88)
 * so authenticated users bypass this listener entirely, but before
 * RejectListener (77) and LoginListener (66) so public traffic is not
 * subject to the login rate limiter.
 *
 * When the request path matches a configured public path pattern:
 *  - If within rate limit → 200 OK (no Remote-User header)
 *  - If over rate limit → 429 Too Many Requests with Retry-After header
 *
 * Non-matching paths fall through to the normal auth flow.
 */
final readonly class PublicAccessListener
{
    use HasLoggerTrait;

    private RateLimiterFactoryInterface $rateLimiter;

    public function __construct(
        private PublicPathMatcherInterface $pathMatcher,
        private DomainInterface           $domainManager,
        private Environment               $twig,
        #[Target('public_limiter')] RateLimiterFactoryInterface $rateLimiter,
    ) {
        $this->rateLimiter = $rateLimiter;
    }

    /** @throws SyntaxError|RuntimeError|LoaderError */
    #[AsEventListener(priority: 84)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if ($this->pathMatcher->isEmpty()) {
            return;
        }

        $request = $event->getRequest();
        $host = $request->getHost();
        $path = $request->getPathInfo();

        // Never treat the auth subdomain itself as public
        if ($this->domainManager->getAuthSubdomain() === $host) {
            return;
        }

        if (! $this->pathMatcher->matches($host, $path)) {
            return;
        }

        // Path is public — apply rate limiting
        $limiter = $this->rateLimiter->create($request->getClientIp());
        $limit = $limiter->consume(1);

        if ($limit->isAccepted()) {
            $this->logger->debug("public access granted: {$request->getClientIp()} -> $path");
            $event->setResponse(new Response(
                '',
                Response::HTTP_OK,
                [
                    'Content-Type'   => 'text/plain',
                    'Retry-After'    => (string) $limit->getRemainingTokens(),
                ],
            ));
        } else {
            $retryAfter = $limit->getRetryAfter()?->getTimestamp() - time();
            $retryAfter = max(1, $retryAfter);

            $this->logger->debug("public access rate-limited: {$request->getClientIp()} -> $path");
            $html = $this->twig->render('error.html.twig');
            $event->setResponse(new Response(
                $html,
                Response::HTTP_TOO_MANY_REQUESTS,
                [
                    'Content-Type' => 'text/html',
                    'Retry-After'  => (string) $retryAfter,
                ],
            ));
        }
    }
}
