<?php

declare(strict_types=1);

namespace App\Listener;

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
         * for those. No external resources are loaded. */
        $headers->set(
            'Content-Security-Policy',
            "default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline';"
        );

        /* HSTS — enforce HTTPS for one year (app is designed for HTTPS behind a proxy) */
        $headers->set('Strict-Transport-Security', 'max-age=31536000');
    }
}
