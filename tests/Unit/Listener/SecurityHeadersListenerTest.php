<?php

declare(strict_types=1);

namespace App\Tests\Unit\Listener;

use App\Listener\SecurityHeadersListener;
use App\Service\DomainInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SecurityHeadersListenerTest extends TestCase
{
    private function makeListener(?string $authSubdomain = null): SecurityHeadersListener
    {
        $domainManager = $this->createStub(DomainInterface::class);
        $domainManager->method('getAuthSubdomain')->willReturn($authSubdomain);

        return new SecurityHeadersListener($domainManager);
    }

    private function makeEvent(
        Response $response,
        ?Request $request = null,
        int $requestType = HttpKernelInterface::MAIN_REQUEST,
    ): ResponseEvent {
        return new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request ?? Request::create('https://example.com/', 'GET'),
            $requestType,
            $response,
        );
    }

    /**
     * The emitted Cache-Control is normalized by Symfony (directives are
     * reordered), so assert on directives rather than the exact string.
     */
    private function assertNoStoreHeaders(Response $response): void
    {
        self::assertTrue($response->headers->hasCacheControlDirective('no-cache'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($response->headers->hasCacheControlDirective('must-revalidate'));
        self::assertTrue($response->headers->hasCacheControlDirective('proxy-revalidate'));
        self::assertSame('0', $response->headers->getCacheControlDirective('max-age'));
        self::assertSame('0', $response->headers->getCacheControlDirective('s-maxage'));
        self::assertSame('no-cache', $response->headers->get('Pragma'));
        self::assertSame('0', $response->headers->get('Expires'));
        self::assertSame('no-store', $response->headers->get('Surrogate-Control'));
        self::assertSame('*', $response->headers->get('Vary'));
    }

    private function assertNoAntiCachingHeaders(Response $response): void
    {
        self::assertFalse($response->headers->hasCacheControlDirective('no-store'));
        self::assertNull($response->headers->get('Pragma'));
        self::assertNull($response->headers->get('Expires'));
        self::assertNull($response->headers->get('Surrogate-Control'));
        self::assertNull($response->headers->get('Vary'));
    }

    /* ── non-2xx: the login flow must not be cacheable ────────────────── */

    public function test_login_page_response_is_not_cacheable(): void
    {
        $listener = $this->makeListener();
        $response = new Response('<form>login</form>', Response::HTTP_UNAUTHORIZED);
        $event = $this->makeEvent($response);

        $listener->onKernelResponse($event);

        $this->assertNoStoreHeaders($response);
    }

    public function test_redirect_response_is_not_cacheable(): void
    {
        $listener = $this->makeListener();
        $response = new Response('', Response::HTTP_SEE_OTHER, [
            'Location' => 'https://example.com/dashboard',
        ]);
        $event = $this->makeEvent($response);

        $listener->onKernelResponse($event);

        $this->assertNoStoreHeaders($response);
        // the redirect target must survive
        self::assertSame('https://example.com/dashboard', $response->headers->get('Location'));
    }

    public function test_rate_limited_response_is_not_cacheable(): void
    {
        $listener = $this->makeListener();
        $response = new Response('<h1>teapot</h1>', Response::HTTP_I_AM_A_TEAPOT);
        $event = $this->makeEvent($response);

        $listener->onKernelResponse($event);

        $this->assertNoStoreHeaders($response);
    }

    public function test_server_error_response_is_not_cacheable(): void
    {
        $listener = $this->makeListener();
        $response = new Response('error', Response::HTTP_INTERNAL_SERVER_ERROR);
        $event = $this->makeEvent($response);

        $listener->onKernelResponse($event);

        $this->assertNoStoreHeaders($response);
    }

    /* ── 2xx: authenticated / public grants stay untouched ────────────── */

    public function test_successful_authenticated_response_is_not_touched(): void
    {
        $listener = $this->makeListener();
        $response = new Response('hi alice', Response::HTTP_OK, [
            'Remote-User' => 'alice',
            'Content-Type' => 'text/plain',
        ]);
        $event = $this->makeEvent($response);

        $listener->onKernelResponse($event);

        // "already authenticated" responses are consumed by the reverse
        // proxy's forward_auth check and never reach the browser, so they
        // must not carry the anti-caching headers (or they could leak onto
        // the protected service's own responses in custom configurations)
        $this->assertNoAntiCachingHeaders($response);
        self::assertSame('alice', $response->headers->get('Remote-User'));
    }

    public function test_successful_response_keeps_its_own_cache_headers(): void
    {
        $listener = $this->makeListener();
        $response = new Response('ok', Response::HTTP_OK, [
            'Cache-Control' => 'public, max-age=60',
        ]);
        $event = $this->makeEvent($response);

        $listener->onKernelResponse($event);

        // the service's caching decisions are its own business;
        // Symfony normalizes directive order, so assert semantically
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame('60', $response->headers->getCacheControlDirective('max-age'));
        self::assertFalse($response->headers->hasCacheControlDirective('no-store'));
    }

    /* ── sub-requests ─────────────────────────────────────────────────── */

    public function test_sub_requests_are_skipped(): void
    {
        $listener = $this->makeListener();
        $response = new Response('login', Response::HTTP_UNAUTHORIZED);
        $event = $this->makeEvent($response, null, HttpKernelInterface::SUB_REQUEST);

        $listener->onKernelResponse($event);

        self::assertFalse($response->headers->hasCacheControlDirective('no-store'));
        self::assertNull($response->headers->get('X-Frame-Options'));
    }

    /* ── the pre-existing security headers ────────────────────────────── */

    public function test_security_headers_are_applied(): void
    {
        $listener = $this->makeListener();
        $response = new Response('<form>login</form>', Response::HTTP_UNAUTHORIZED);
        $event = $this->makeEvent($response);

        $listener->onKernelResponse($event);

        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        self::assertSame('max-age=31536000', $response->headers->get('Strict-Transport-Security'));
    }

    public function test_csp_allows_same_origin_connect_when_inline_script_is_used(): void
    {
        // not on the auth subdomain: the login form uses an inline fetch()
        $listener = $this->makeListener('auth.example.com');
        $response = new Response('login', Response::HTTP_UNAUTHORIZED);
        $event = $this->makeEvent($response);

        $listener->onKernelResponse($event);

        self::assertStringContainsString("connect-src 'self';", $response->headers->get('Content-Security-Policy'));
    }

    public function test_csp_does_not_allow_connect_when_on_auth_subdomain(): void
    {
        // on the auth subdomain the form POSTs normally — no inline fetch
        $listener = $this->makeListener('auth.example.com');
        $response = new Response('login', Response::HTTP_UNAUTHORIZED);
        $request = Request::create('https://auth.example.com/', 'GET');
        $event = $this->makeEvent($response, $request);

        $listener->onKernelResponse($event);

        self::assertStringNotContainsString('connect-src', $response->headers->get('Content-Security-Policy'));
    }
}
