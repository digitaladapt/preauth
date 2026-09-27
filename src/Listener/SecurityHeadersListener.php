<?php

declare(strict_types=1);

namespace App\Listener;

use App\Service\DomainInterface;
use App\Service\PasskeyPolicyInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
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
        private PasskeyPolicyInterface $passkeyPolicy,
    ) {
    }

    #[AsEventListener(priority: 0)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        $headers = $response->headers;

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
        $csp = "default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline';";

        /* `publickey-credentials-get`/`-create` do NOT fall back to default-src,
         * so without these directives the browser refuses the ceremony even
         * though the script itself is allowed to run. `connect-src 'self'` is
         * needed in both modes here, because the passkey flow always talks to
         * the server with fetch(). */
        if ($this->passkeyPolicy->isAvailableFor($event->getRequest())) {
            $csp .= " connect-src 'self'; publickey-credentials-get 'self'; publickey-credentials-create 'self';";
        } elseif ($inlineScript) {
            $csp .= " connect-src 'self';";
        }

        $headers->set('Content-Security-Policy', $csp);

        /* HSTS — enforce HTTPS for one year (app is designed for HTTPS behind a proxy) */
        $headers->set('Strict-Transport-Security', 'max-age=31536000');

        /* Prevent any part of the login flow from being cached: the login
         * page, failed logins, redirects, and rate-limit/error pages must
         * never be stored or replayed by the browser or an intermediate
         * cache — older Safari builds in particular may otherwise resurrect
         * a stale pre-auth response, appearing to log the user out after a
         * refresh or showing a previous session after logging in again.
         *
         * Only non-2xx responses are touched: the 2xx responses that grant
         * access ("already authenticated" or public) are consumed by the
         * reverse proxy's forward_auth check before reaching the browser,
         * and the protected service's own cache headers must remain
         * untouched.
         *
         * A ceremony reply is the exception that proves the rule: it is a 2xx
         * that goes straight to the browser, because the auth subdomain has no
         * forward_auth in front of it. Left alone it would be cacheable, so a
         * browser could replay a stale challenge. `PasskeyListener` marks those
         * responses and the marker is stripped here. */
        if (!$response->isSuccessful()) {
            $this->applyNoStore($headers);

            return;
        }

        if ($headers->has(PasskeyListener::CEREMONY_MARKER)) {
            $headers->remove(PasskeyListener::CEREMONY_MARKER);
            $this->applyNoStore($headers);
        }
    }

    private function applyNoStore(ResponseHeaderBag $headers): void
    {
        $headers->set('Cache-Control', 'no-cache, no-store, must-revalidate, proxy-revalidate, max-age=0, s-maxage=0');
        $headers->set('Pragma', 'no-cache');
        $headers->set('Expires', '0');
        $headers->set('Surrogate-Control', 'no-store');
        $headers->set('Vary', '*');
    }
}
