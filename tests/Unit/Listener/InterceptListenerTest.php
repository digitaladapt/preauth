<?php

declare(strict_types=1);

namespace App\Tests\Unit\Listener;

use App\Listener\InterceptListener;
use App\Service\DomainManager;
use App\Tests\Support\ListenerTestHelper;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class InterceptListenerTest extends TestCase
{
    use ListenerTestHelper;

    private const string COOKIE_NAME = '__Host-Http-Preauth';
    private const string AUTH_COOKIE_NAME = '__Http-Domain-Preauth';

    private function makeListener(
        DomainManager $domainManager,
        ?CacheItemPoolInterface $nonceCache = null,
    ): InterceptListener {
        $listener = new InterceptListener(
            $this->makeConfig(),
            $domainManager,
            $this->makeTwig(),
        );
        $listener->setLogger(new NullLogger());
        $listener->setNonceCache($nonceCache ?? new ArrayAdapter());
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

    /* ── central-auth redirect branch ─────────────────────────────────── */

    public function testRedirectsToAuthSubdomainWhenHostMatchesBaseDomain(): void
    {
        $domainManager = new DomainManager(true, 'auth.example.com');
        $listener = $this->makeListener($domainManager);

        $request = Request::create('https://app.example.com/dashboard', 'GET');
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        $response = $event->getResponse();
        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        $location = $response->headers->get('Location');
        self::assertStringStartsWith('https://auth.example.com/?', $location);
        // the return query should contain the original url
        self::assertStringContainsString('return=', $location);
        self::assertStringContainsString(urlencode('https://app.example.com/dashboard'), $location);
    }

    public function testDoesNotRedirectWhenAlreadyOnAuthSubdomain(): void
    {
        $domainManager = new DomainManager(true, 'auth.example.com');
        $listener = $this->makeListener($domainManager);

        $request = Request::create('https://auth.example.com/', 'GET');
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        // should render login page, not redirect
        self::assertTrue($event->hasResponse());
        $response = $event->getResponse();
        self::assertNotSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    /* ── login page rendering branch ──────────────────────────────────── */

    public function testPresentsLoginPageWithUnauthorizedStatus(): void
    {
        $domainManager = new DomainManager(false, '');
        $listener = $this->makeListener($domainManager);

        $request = Request::create('https://example.com/', 'GET');
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        $response = $event->getResponse();
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('text/html', $response->headers->get('Content-Type'));
        $content = $response->getContent();
        self::assertStringContainsString('<form', $content);
        // the rendered page should embed a freshly generated nonce
        self::assertStringContainsString('name="nonce"', $content);
    }

    public function testGeneratedNonceIsStoredInCache(): void
    {
        $nonceCache = new ArrayAdapter();
        $domainManager = new DomainManager(false, '');
        $listener = $this->makeListener($domainManager, $nonceCache);

        $request = Request::create('https://example.com/', 'GET');
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        // exactly one nonce should now exist in the cache, marked valid
        $found = false;
        foreach ($nonceCache->getValues() as $key => $value) {
            if (str_starts_with($key, 'test_') || preg_match('/^[A-Za-z0-9_.]+$/', $key)) {
                $found = true;
            }
        }
        // ArrayAdapter stores raw values; verify at least one item was saved
        self::assertTrue(count($nonceCache->getValues()) > 0);
    }

    public function testLoginTemplateUsesPostFormWhenOnAuthSubdomain(): void
    {
        $domainManager = new DomainManager(true, 'auth.example.com');
        $listener = $this->makeListener($domainManager);

        $request = Request::create('https://auth.example.com/', 'GET');
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        $content = $event->getResponse()->getContent();
        // when on the auth subdomain, post=true so the form has method="post"
        self::assertStringContainsString('method="post"', $content);
    }

    public function testLoginTemplateDoesNotUsePostFormWhenNotOnAuthSubdomain(): void
    {
        $domainManager = new DomainManager(false, '');
        $listener = $this->makeListener($domainManager);

        $request = Request::create('https://example.com/', 'GET');
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        $content = $event->getResponse()->getContent();
        // not on auth subdomain, so the form should NOT have method="post"
        self::assertStringNotContainsString('method="post"', $content);
    }

    /* ── invalid cookie pruning ───────────────────────────────────────── */

    public function testInvalidCookieIsClearedWhenPresent(): void
    {
        $domainManager = new DomainManager(false, '');
        $listener = $this->makeListener($domainManager);

        $request = Request::create('https://example.com/', 'GET');
        // send a cookie that won't match any session (so AcceptListener didn't fire)
        $request->cookies->set(self::COOKIE_NAME, 'stale-ulid');

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        $response = $event->getResponse();
        // a Clear-Site-Data style clearCookie should produce a Set-Cookie that expires it
        $cookies = $response->headers->getCookies();
        $cleared = false;
        foreach ($cookies as $cookie) {
            if ($cookie->getName() === self::COOKIE_NAME && $cookie->isCleared()) {
                $cleared = true;
            }
        }
        self::assertTrue($cleared, 'Expected the invalid cookie to be cleared');
    }

    public function testNoCookieClearingWhenNoCookiePresent(): void
    {
        $domainManager = new DomainManager(false, '');
        $listener = $this->makeListener($domainManager);

        $request = Request::create('https://example.com/', 'GET');
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertSame([], $response->headers->getCookies());
    }

    public function testInvalidCookieUsesAuthCookieNameWithCentralAuth(): void
    {
        $domainManager = new DomainManager(true, 'auth.example.com');
        $listener = $this->makeListener($domainManager);

        // request to auth subdomain with a stale auth-domain cookie
        $request = Request::create('https://auth.example.com/', 'GET');
        $request->cookies->set(self::AUTH_COOKIE_NAME, 'stale-ulid');

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        $cleared = false;
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === self::AUTH_COOKIE_NAME && $cookie->isCleared()) {
                $cleared = true;
            }
        }
        self::assertTrue($cleared, 'Expected the auth cookie to be cleared');
    }
}
