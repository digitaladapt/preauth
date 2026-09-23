<?php

declare(strict_types=1);

namespace App\Tests\Unit\Listener;

use App\Listener\PublicAccessListener;
use App\Service\DomainInterface;
use App\Service\PublicPathMatcher;
use App\Tests\Support\ListenerTestHelper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Unit tests for PublicAccessListener.
 *
 * @covers \App\Listener\PublicAccessListener
 */
final class PublicAccessListenerTest extends TestCase
{
    use ListenerTestHelper;

    private function makeListener(
        string $publicPaths = '',
        int $remainingTokens = 10,
        ?string $authSubdomain = null,
    ): PublicAccessListener {
        $pathMatcher = new PublicPathMatcher($publicPaths);

        $domainManager = $this->createStub(DomainInterface::class);
        $domainManager->method('getAuthSubdomain')->willReturn($authSubdomain);

        $listener = new PublicAccessListener(
            $pathMatcher,
            $domainManager,
            $this->makeTwig(),
            $this->makeRateLimiterFactory($remainingTokens),
        );
        $listener->setLogger(new NullLogger());

        return $listener;
    }

    private function makeEvent(Request $request): RequestEvent
    {
        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    /* ── feature disabled ──────────────────────────────────────────────── */

    public function test_no_public_paths_returns_without_response(): void
    {
        $listener = $this->makeListener(publicPaths: '');

        $request = Request::create('/public', 'GET', [], [], [], ['REMOTE_ADDR' => '1.2.3.4']);
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    /* ── non-public path ───────────────────────────────────────────────── */

    public function test_non_public_path_returns_without_response(): void
    {
        $listener = $this->makeListener(publicPaths: '/public/**');

        $request = Request::create('/private', 'GET', [], [], [], ['REMOTE_ADDR' => '1.2.3.4']);
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    /* ── public path within rate limit ─────────────────────────────────── */

    public function test_public_path_within_rate_limit_returns200(): void
    {
        $listener = $this->makeListener(publicPaths: '/public/**', remainingTokens: 10);

        $request = Request::create('/public/repo', 'GET', [], [], [], ['REMOTE_ADDR' => '1.2.3.4']);
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        $response = $event->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('text/plain', $response->headers->get('Content-Type'));
        // No Remote-User header for public access
        self::assertFalse($response->headers->has('Remote-User'));
    }

    /* ── public path rate limited ──────────────────────────────────────── */

    public function test_public_path_over_rate_limit_returns429(): void
    {
        $listener = $this->makeListener(publicPaths: '/public/**', remainingTokens: 0);

        $request = Request::create('/public/repo', 'GET', [], [], [], ['REMOTE_ADDR' => '1.2.3.4']);
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        $response = $event->getResponse();
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        self::assertSame('text/html', $response->headers->get('Content-Type'));
        self::assertTrue($response->headers->has('Retry-After'));
    }

    public function test_rate_limited_response_contains_error_template(): void
    {
        $listener = $this->makeListener(publicPaths: '/public/**', remainingTokens: 0);

        $request = Request::create('/public/repo', 'GET', [], [], [], ['REMOTE_ADDR' => '1.2.3.4']);
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        $content = $event->getResponse()->getContent();
        // Default teapot template content (env.teapot is true in test helper)
        self::assertStringContainsString('teapot', $content);
    }

    /* ── auth subdomain is never public ────────────────────────────────── */

    public function test_auth_subdomain_request_is_skipped(): void
    {
        $listener = $this->makeListener(
            publicPaths: '/**',
            remainingTokens: 10,
            authSubdomain: 'auth.example.com',
        );

        // Request to auth subdomain — should NOT be treated as public
        $request = Request::create('https://auth.example.com/public', 'GET', [], [], [], ['REMOTE_ADDR' => '1.2.3.4']);
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    /* ── query string is ignored ───────────────────────────────────────── */

    public function test_query_string_is_ignored_for_path_matching(): void
    {
        $listener = $this->makeListener(publicPaths: '/public', remainingTokens: 10);

        $request = Request::create('/public?foo=bar', 'GET', [], [], [], ['REMOTE_ADDR' => '1.2.3.4']);
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_OK, $event->getResponse()->getStatusCode());
    }

    /* ── domain-scoped paths ───────────────────────────────────────────── */

    public function test_domain_scoped_path_matches_correct_host(): void
    {
        $listener = $this->makeListener(publicPaths: 'code.example.com/public/**', remainingTokens: 10);

        $request = Request::create('https://code.example.com/public/repo', 'GET', [], [], [], ['REMOTE_ADDR' => '1.2.3.4']);
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_OK, $event->getResponse()->getStatusCode());
    }

    public function test_domain_scoped_path_does_not_match_other_host(): void
    {
        $listener = $this->makeListener(publicPaths: 'code.example.com/public/**', remainingTokens: 10);

        $request = Request::create('https://other.example.com/public/repo', 'GET', [], [], [], ['REMOTE_ADDR' => '1.2.3.4']);
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    /* ── wildcard matching ─────────────────────────────────────────────── */

    public function test_single_wildcard_matching(): void
    {
        $listener = $this->makeListener(publicPaths: '/public/*', remainingTokens: 10);

        $request = Request::create('/public/repo', 'GET', [], [], [], ['REMOTE_ADDR' => '1.2.3.4']);
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_OK, $event->getResponse()->getStatusCode());
    }

    public function test_single_wildcard_does_not_match_deep_path(): void
    {
        $listener = $this->makeListener(publicPaths: '/public/*', remainingTokens: 10);

        $request = Request::create('/public/a/b', 'GET', [], [], [], ['REMOTE_ADDR' => '1.2.3.4']);
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    /* ── 200 response includes remaining token count ───────────────────── */

    public function test_ok_response_includes_retry_after_header(): void
    {
        // The 200 response includes a Retry-After header showing remaining tokens
        $listener = $this->makeListener(publicPaths: '/public/**', remainingTokens: 42);

        $request = Request::create('/public/repo', 'GET', [], [], [], ['REMOTE_ADDR' => '1.2.3.4']);
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('42', $response->headers->get('Retry-After'));
    }
}
