<?php
declare(strict_types=1);

namespace App\Tests\Unit\Listener;

use App\ConfigBag;
use App\Data\Payload;
use App\Listener\LoginListener;
use App\Service\DomainManager;
use App\Service\LoginManager;
use App\Utilities;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Twig\Environment;

final class LoginListenerTest extends TestCase {
    private function createEvent(Request $request): RequestEvent {
        $kernel = $this->createMock(HttpKernelInterface::class);
        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function createConfigBag(bool $teapot = false): ConfigBag {
        $clock = $this->createMock(ClockInterface::class);
        $cache = $this->createMock(CacheItemPoolInterface::class);
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

    private function createLimiter(int $remainingTokens): LimiterInterface {
        $limit = $this->createMock(RateLimit::class);
        $limit->method('getRemainingTokens')->willReturn($remainingTokens);

        $limiter = $this->createMock(LimiterInterface::class);
        $limiter->method('consume')->with(1)->willReturn($limit);
        return $limiter;
    }

    private function createListener(
        ?LoginManager $loginManager = null,
        ?DomainManager $domainManager = null,
        ?RateLimiterFactoryInterface $rateLimiter = null,
        bool $teapot = false
    ): LoginListener {
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->with('login.html.twig', self::anything())->willReturn('<html>login</html>');

        $rateLimiter = $rateLimiter ?? $this->createMock(RateLimiterFactoryInterface::class);
        $rateLimiter->method('create')->willReturn($this->createLimiter(5));

        $dm = $domainManager ?? new DomainManager(false, '');
        $lm = $loginManager ?? $this->createMock(LoginManager::class);
        $config = $this->createConfigBag($teapot);

        $listener = new LoginListener($twig, $rateLimiter, $dm, $lm, $config);
        $listener->setLogger($this->createMock(LoggerInterface::class));

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);
        $item->method('set')->willReturnSelf();
        $item->method('expiresAfter')->willReturnSelf();
        $cache->method('getItem')->willReturn($item);
        $cache->method('save')->willReturn(true);
        $listener->setNonceCache($cache);

        return $listener;
    }

    public function testOnKernelRequestWithNoLoginAttempt(): void {
        $listener = $this->createListener();

        $request = Request::create('https://example.com/');
        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testOnKernelRequestWithHeaderLoginSuccess(): void {
        $loginManager = $this->createMock(LoginManager::class);
        $loginManager->method('checkToken')->willReturnCallback(function (Payload $payload) {
            return new \Symfony\Component\HttpFoundation\Response('success');
        });

        $listener = $this->createListener($loginManager);

        $request = Request::create('https://example.com/');
        $request->headers->set('X-Preauth', base64_encode(json_encode([
            'id' => 'user1', 'token' => '123456', 'nonce' => 'abc', 'json' => true, 'scope' => 'cookie'
        ])));

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame('success', $response->getContent());
    }

    public function testOnKernelRequestWithPostLoginSuccess(): void {
        $loginManager = $this->createMock(LoginManager::class);
        $loginManager->method('checkToken')->willReturn(new \Symfony\Component\HttpFoundation\Response('success'));

        $dm = new DomainManager(true, 'auth.example.com');
        $listener = $this->createListener($loginManager, $dm);

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

    public function testOnKernelRequestWithFailedLoginReturnsUnauthorized(): void {
        $loginManager = $this->createMock(LoginManager::class);
        $loginManager->method('checkToken')->willReturn(null);

        $listener = $this->createListener($loginManager);

        $request = Request::create('https://example.com/');
        $request->headers->set('X-Preauth', base64_encode(json_encode([
            'id' => 'user1', 'token' => 'bad', 'nonce' => 'abc', 'json' => true, 'scope' => 'cookie'
        ])));

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(401, $response->getStatusCode());
    }

    public function testOnKernelRequestWithRateLimitReached(): void {
        $loginManager = $this->createMock(LoginManager::class);
        $loginManager->method('checkToken')->willReturn(null);

        $rateLimiter = $this->createMock(RateLimiterFactoryInterface::class);
        $limit = $this->createMock(RateLimit::class);
        $limit->method('getRemainingTokens')->willReturn(0);
        $limiter = $this->createMock(LimiterInterface::class);
        $limiter->method('consume')->with(1)->willReturn($limit);
        $rateLimiter->method('create')->willReturn($limiter);

        $listener = $this->createListener($loginManager, null, $rateLimiter);

        $request = Request::create('https://example.com/');
        $request->headers->set('X-Preauth', base64_encode(json_encode([
            'id' => 'user1', 'token' => 'bad', 'nonce' => 'abc', 'json' => true, 'scope' => 'cookie'
        ])));

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(429, $response->getStatusCode());
    }

    public function testOnKernelRequestWithRateLimitReachedAndTeapot(): void {
        $loginManager = $this->createMock(LoginManager::class);
        $loginManager->method('checkToken')->willReturn(null);

        $rateLimiter = $this->createMock(RateLimiterFactoryInterface::class);
        $limit = $this->createMock(RateLimit::class);
        $limit->method('getRemainingTokens')->willReturn(0);
        $limiter = $this->createMock(LimiterInterface::class);
        $limiter->method('consume')->with(1)->willReturn($limit);
        $rateLimiter->method('create')->willReturn($limiter);

        $listener = $this->createListener($loginManager, null, $rateLimiter, true);

        $request = Request::create('https://example.com/');
        $request->headers->set('X-Preauth', base64_encode(json_encode([
            'id' => 'user1', 'token' => 'bad', 'nonce' => 'abc', 'json' => true, 'scope' => 'cookie'
        ])));

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(418, $response->getStatusCode());
    }

    public function testOnKernelRequestWithJsonResponse(): void {
        $loginManager = $this->createMock(LoginManager::class);
        $loginManager->method('checkToken')->willReturn(null);

        $listener = $this->createListener($loginManager);

        $request = Request::create('https://example.com/');
        $request->headers->set('X-Preauth', base64_encode(json_encode([
            'id' => 'user1', 'token' => 'bad', 'nonce' => 'abc', 'json' => true, 'scope' => 'cookie'
        ])));

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame('application/json', $response->headers->get('Content-Type'));
    }

    public function testOnKernelRequestWithHtmlResponse(): void {
        $loginManager = $this->createMock(LoginManager::class);
        $loginManager->method('checkToken')->willReturn(null);

        $listener = $this->createListener($loginManager);

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
