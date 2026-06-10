<?php
declare(strict_types=1);

namespace App\Tests\Unit\Listener;

use App\ConfigBag;
use App\Listener\LoginListener;
use App\Service\DomainManager;
use App\Service\LoginInterface;
use App\Utilities;
use OTPHP\TOTP;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Twig\Environment;

final class LoginListenerTest extends TestCase {
    private function createEvent(Request $request): RequestEvent {
        $kernel = $this->createStub(HttpKernelInterface::class);
        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function createConfigBag(bool $teapot = false): ConfigBag {
        $clock = $this->createStub(ClockInterface::class);
        $cache = $this->createStub(CacheItemPoolInterface::class);
        $utilities = new Utilities($clock, $cache);
        $totp = TOTP::generate($clock);
        $totp->setLabel('Test');

        return new ConfigBag(
            $utilities,
            $clock,
            3600,
            $totp->getProvisioningUri(),
            1800,
            $teapot,
            'Error!',
            'Teapot!',
            'Too Many!'
        );
    }

    private function createLimiter(InvocationOrder $expectation, int $remainingTokens): LimiterInterface {
        $limit = $this->createMock(RateLimit::class);
        $limit->expects($expectation)->method('getRemainingTokens')
            ->willReturn($remainingTokens);

        $limiter = $this->createMock(LimiterInterface::class);
        $limiter->expects($expectation)->method('consume')
            ->with(1)->willReturn($limit);
        return $limiter;
    }

    /**
     * @param LoginInterface|null $loginManager
     * @param DomainManager|null $domainManager
     * @param bool $teapot Setting teapot variable used in config
     * @param bool $reject Make the LoginInterface::checkToken return null when true
     * @param int $limit Number of remaining login attempt tokens
     * @param bool $html Indicates if we expect login form to be produced
     * @param bool $cacheUsed Indicates if we expect the nonce cache to be used
     * @return LoginListener */
    private function createListener(
        ?LoginInterface $loginManager = null,
        ?DomainManager $domainManager = null,
        bool $teapot = false,
        bool $reject = false,
        int $limit = 5,
        bool $html = false,
        bool $cacheUsed = true,
    ): LoginListener {
        /* we expect the login page when request is rejected and limit not reached */
        $twig = $this->createMock(Environment::class);
        $twig->expects($html ? $this->once() : $this->never())
            ->method('render')->with('login.html.twig', self::anything())
            ->willReturn('<html>login</html>');

        $expectation = $reject ? $this->atLeastOnce() : $this->never();
        $rateLimiter = $this->createStub(RateLimiterFactoryInterface::class);
        $rateLimiter->method('create')->willReturn($this->createLimiter($expectation, $limit));

        $dm = $domainManager ?? new DomainManager(false, '');
        if ( ! $loginManager) {
            $loginManager = $this->createMock(LoginInterface::class);
            $loginManager->expects($this->once())->method('checkToken')
                ->willReturn($reject ? null : new Response('success'));
        }
        $lm = $loginManager;
        $config = $this->createConfigBag($teapot);

        $listener = new LoginListener($twig, $rateLimiter, $dm, $lm, $config);
        $listener->setLogger($this->createStub(LoggerInterface::class));

        $cacheExpected = $cacheUsed ? $this->atLeastOnce() : $this->never();
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $item = $this->createMock(CacheItemInterface::class);
        $item->expects($cacheExpected)->method('isHit')->willReturn(false);
        $item->expects($cacheExpected)->method('set')->willReturnSelf();
        $item->expects($cacheExpected)->method('expiresAfter')->willReturnSelf();
        $cache->expects($cacheExpected)->method('getItem')->willReturn($item);
        $cache->expects($cacheExpected)->method('save')->willReturn(true);
        $listener->setNonceCache($cache);

        return $listener;
    }

    /** no login attempt should be quietly ignored */
    public function testOnKernelRequestWithNoLoginAttempt(): void {
        $listener = $this->createListener($this->createStub(LoginInterface::class), cacheUsed: false);

        $request = Request::create('https://example.com/');
        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    /** valid login via header will return success response */
    public function testOnKernelRequestWithHeaderLoginSuccess(): void {
        $listener = $this->createListener(cacheUsed: false);

        $request = Request::create('https://example.com/');
        $request->headers->set('X-Preauth', base64_encode(json_encode([
            'id' => 'user1',
            'token' => '123456',
            'nonce' => 'abc',
            'json' => true,
            'scope' => 'cookie',
        ])));

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame('success', $response->getContent());
    }

    /** valid login via post will return success response */
    public function testOnKernelRequestWithPostLoginSuccess(): void {
        $dm = new DomainManager(true, 'auth.example.com');
        $listener = $this->createListener(domainManager: $dm, cacheUsed: false);

        $request = Request::create('https://auth.example.com/', 'POST', [
            'username' => 'user1',
            'nonce' => 'abc',
            'totp' => '123456',
        ]);

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame('success', $response->getContent());
    }

    /** rejected login will return unauthorized */
    public function testOnKernelRequestWithFailedLoginReturnsUnauthorized(): void {
        $listener = $this->createListener(reject: true);

        $request = Request::create('https://example.com/');
        $request->headers->set('X-Preauth', base64_encode(json_encode([
            'id' => 'user1',
            'token' => 'bad',
            'nonce' => 'abc',
            'json' => true,
            'scope' => 'cookie',
        ])));

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(401, $response->getStatusCode());
    }

    /** rejected login and reached rate-limit will return too-many */
    public function testOnKernelRequestWithRateLimitReached(): void {
        $listener = $this->createListener(reject: true, limit: 0);

        $request = Request::create('https://example.com/');
        $request->headers->set('X-Preauth', base64_encode(json_encode([
            'id' => 'user1',
            'token' => 'bad',
            'nonce' => 'abc',
            'json' => true,
            'scope' => 'cookie',
        ])));

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(429, $response->getStatusCode());
    }

    /** rejected login and reached rate-limit with teapot will return teapot */
    public function testOnKernelRequestWithRateLimitReachedAndTeapot(): void {
        $listener = $this->createListener(teapot: true, reject: true, limit: 0);

        $request = Request::create('https://example.com/');
        $request->headers->set('X-Preauth', base64_encode(json_encode([
            'id' => 'user1',
            'token' => 'bad',
            'nonce' => 'abc',
            'json' => true,
            'scope' => 'cookie',
        ])));

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(418, $response->getStatusCode());
    }

    /** rejected login with json will return as json */
    public function testOnKernelRequestWithJsonResponse(): void {
        $listener = $this->createListener(reject: true);

        $request = Request::create('https://example.com/');
        $request->headers->set('X-Preauth', base64_encode(json_encode([
            'id' => 'user1',
            'token' => 'bad',
            'nonce' => 'abc',
            'json' => true,
            'scope' => 'cookie',
        ])));

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame('application/json', $response->headers->get('Content-Type'));
    }

    /** rejected login via POST will return as html */
    public function testOnKernelRequestWithHtmlResponse(): void {
        $listener = $this->createListener(
            domainManager: new DomainManager(true, 'auth.example.com'),
            reject: true,
            html: true,
        );

        $request = Request::create('https://auth.example.com/', 'POST', [
            'username' => 'user1',
            'nonce' => 'abc',
            'totp' => 'bad',
        ]);

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame('text/html', $response->headers->get('Content-Type'));
    }
}
