<?php

declare(strict_types=1);

namespace App\Listener;

use App\Service\DomainInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Adds security-related HTTP response headers to all responses.
 * These headers help protect against XSS, clickjacking, MIME-type
 * sniffing, and referrer leakage.
 */
final readonly class SecurityHeadersListener
{
    public function __construct(
        private DomainInterface $domainManager,
    ) {
    }

    #[AsEventListener(priority: 0)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (! $event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        $headers  = $response->headers;

        /* prevent MIME-type sniffing */
        $headers->set('X-Content-Type-Options', 'nosniff');

        /* prevent clickjacking — this app is never framed */
        $headers->set('X-Frame-Options', 'DENY');

        /* control referrer information sent to other sites */
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        /* Content-Security-Policy — the login page uses inline styles
         * and scripts (via Twig includes), so we allow 'unsafe-inline'
         * for those. No external resources are loaded.
         *
         * When subdomain redirection is off (or the request is not on
         * the auth subdomain), the login form is served inline on the
         * protected host and submission is performed via a same-origin
         * fetch() call in _script.html.twig. That fetch is blocked by
         * the default 'none' policy, so we add connect-src 'self' only
         * in that case — the least privilege needed to make the form
         * work. On the auth subdomain the form POSTs normally and no
         * inline script is included, so the stricter policy applies. */
        $inlineScript = $this->domainManager->getAuthSubdomain() !== $event->getRequest()->getHost();
        $csp          = "default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline';";

        if ($inlineScript) {
            $csp .= " connect-src 'self';";
        }

        $headers->set('Content-Security-Policy', $csp);

        /* HSTS — enforce HTTPS for one year (app is designed for HTTPS behind a proxy) */
        $headers->set('Strict-Transport-Security', 'max-age=31536000');
    }
}
