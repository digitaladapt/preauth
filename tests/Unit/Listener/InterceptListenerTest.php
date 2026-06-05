<?php
declare(strict_types=1);

namespace App\Tests\Unit\Listener;

use App\ConfigBag;
use App\Listener\InterceptListener;
use App\Service\DomainManager;
use App\Utilities;
use OTPHP\TOTP;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Twig\Environment;

final class InterceptListenerTest extends TestCase {
    private function createEvent(Request $request): RequestEvent {
        $kernel = $this->createStub(HttpKernelInterface::class);
        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function createMockLogger(?InvocationOrder $expectation = null): LoggerInterface {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($expectation ?? $this->atLeastOnce())->method('debug');
        return $logger;
    }

    private function createConfigBag(): ConfigBag {
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
            false,
            'Error',
            'Teapot',
            'Too Many'
        );
    }

    private function createTwig(InvocationOrder $expectation): Environment {
        $twig = $this->createMock(Environment::class);
        $twig->expects($expectation)->method('render')
            ->with('login.html.twig', self::anything())->willReturn('<html>login</html>');
        return $twig;
    }

    private function createListener(
        ?DomainManager $dm = null,
        bool $cacheUsed = false,
    ): InterceptListener {
        /* redirect should be quiet, login page renders twig and generates nonce */
        $expectation = $cacheUsed ? $this->atLeastOnce() : $this->never();

        $config = $this->createConfigBag();
        $domainManager = $dm ?? new DomainManager(false, '');
        $listener = new InterceptListener($config, $domainManager, $this->createTwig($expectation));
        $listener->setLogger($this->createMockLogger($expectation));

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $item = $this->createMock(CacheItemInterface::class);
        $item->expects($expectation)->method('isHit')->willReturn(false);
        $item->expects($expectation)->method('set')->willReturnSelf();
        $item->expects($expectation)->method('expiresAfter')->willReturnSelf();
        $cache->expects($expectation)->method('getItem')->willReturn($item);
        $cache->expects($expectation)->method('save')->willReturn(true);
        $listener->setNonceCache($cache);

        return $listener;
    }

    /** ensure when using central-auth that we get redirected from protected subdomain */
    public function testOnKernelRequestRedirectsToAuthSubdomain(): void {
        $dm = new DomainManager(true, 'auth.example.com');
        $listener = $this->createListener($dm);

        $request = Request::create('https://www.example.com/protected');
        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('auth.example.com', $response->headers->get('Location'));
        self::assertStringContainsString('return=', $response->headers->get('Location'));
    }

    /** when using central-auth on auth-subdomain, expect the login page */
    public function testOnKernelRequestPresentsLoginPageOnAuthSubdomain(): void {
        $dm = new DomainManager(true, 'auth.example.com');
        $listener = $this->createListener($dm, true);

        $request = Request::create('https://auth.example.com/');
        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('<html>login</html>', $response->getContent());
    }

    /** when using central-auth on wrong base-domain, expect the login page */
    public function testOnKernelRequestPresentsLoginPageOnRougeDomain(): void {
        $dm = new DomainManager(true, 'auth.example.com');
        $listener = $this->createListener($dm, true);

        $request = Request::create('https://example.org/'); /* different domain */
        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('<html>login</html>', $response->getContent());
    }

    /** when using direct-login, expect the login page */
    public function testOnKernelRequestPresentsLoginPageWithoutAuthSubdomain(): void {
        $listener = $this->createListener(cacheUsed: true);

        $request = Request::create('https://example.com/');
        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('<html>login</html>', $response->getContent());
    }

    /** ensure invalid cookies get cleared with direct-login */
    public function testOnKernelRequestPrunesInvalidCookie(): void {
        $listener = $this->createListener(cacheUsed: true);

        $request = Request::create('https://example.com/');
        $request->cookies->set('__Host-Http-Preauth', 'invalid');

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        $cookies = $response->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertSame('__Host-Http-Preauth', $cookies[0]->getName());
        self::assertNull($cookies[0]->getValue());
    }

    /** only prunes cookies if invalid/expired cookie was sent */
    public function testOnKernelRequestDoesNotPruneCookieWhenNotPresent(): void {
        $listener = $this->createListener(cacheUsed: true);

        $request = Request::create('https://example.com/');
        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertCount(0, $response->headers->getCookies());
    }

    /** ensure invalid cookies get cleared with central-auth */
    public function testOnKernelRequestPruneInvalidAuthBaseCookie(): void {
        $dm = new DomainManager(true, 'auth.example.com');
        $listener = $this->createListener($dm, true);

        $request = Request::create('https://auth.example.com/');
        $request->cookies->set('__Http-Domain-Preauth', 'invalid');

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        $cookies = $response->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertSame('__Http-Domain-Preauth', $cookies[0]->getName());
        self::assertNull($cookies[0]->getValue());
    }
}
