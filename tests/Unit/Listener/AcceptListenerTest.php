<?php
declare(strict_types=1);

namespace App\Tests\Unit\Listener;

use App\Listener\AcceptListener;
use App\Service\DomainManager;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class AcceptListenerTest extends TestCase {
    private function createEvent(Request $request): RequestEvent {
        $kernel = $this->createStub(HttpKernelInterface::class);
        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function createMockItem(mixed $value = null): CacheItemInterface {
        $item = $this->createMock(CacheItemInterface::class);
        $item->expects($this->atLeastOnce())->method('get')->willReturn($value);
        return $item;
    }

    private function createMockLogger(?InvocationOrder $expectation = null): LoggerInterface {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($expectation ?? $this->atLeastOnce())->method('debug');
        return $logger;
    }

    /** ensure accept works when using direct-auth */
    public function testOnKernelRequestWithValidCookie(): void {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $domainManager = new DomainManager(false, '');
        $listener = new AcceptListener($cache, $domainManager);
        $listener->setLogger($this->createMockLogger());

        $cacheItem = $this->createMockItem('user1');
        $cache->expects($this->atLeastOnce())->method('hasItem')
            ->with('cookie_abc123')->willReturn(true);
        $cache->expects($this->atLeastOnce())->method('getItem')
            ->with('cookie_abc123')->willReturn($cacheItem);

        $request = Request::create('https://example.com/');
        $request->cookies->set('__Host-Http-Preauth', 'abc123');

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('hi user1', $response->getContent());
        self::assertSame('user1', $response->headers->get('Remote-User'));
    }

    /** missing cookie should be quietly ignored */
    public function testOnKernelRequestWithNoCookie(): void {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $domainManager = new DomainManager(false, '');
        $listener = new AcceptListener($cache, $domainManager);
        $listener->setLogger($this->createMockLogger($this->never()));

        $cache->expects($this->never())->method('hasItem');

        $request = Request::create('https://example.com/');
        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    /** invalid cookie should be quietly ignored */
    public function testOnKernelRequestWithInvalidCookie(): void {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $domainManager = new DomainManager(false, '');
        $listener = new AcceptListener($cache, $domainManager);
        $listener->setLogger($this->createMockLogger($this->never()));

        $cache->expects($this->atLeastOnce())->method('hasItem')
            ->with('cookie_badcookie')->willReturn(false);

        $request = Request::create('https://example.com/');
        $request->cookies->set('__Host-Http-Preauth', 'badcookie');

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    /** ensure we sanitize cookie input */
    public function testOnKernelRequestWithDangerousCookie(): void {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $domainManager = new DomainManager(false, '');
        $listener = new AcceptListener($cache, $domainManager);
        $listener->setLogger($this->createMockLogger($this->never()));

        $cache->expects($this->atLeastOnce())->method('hasItem')
            ->with('cookie_bad_string_value')->willReturn(false);

        $request = Request::create('https://example.com/');
        $request->cookies->set('__Host-Http-Preauth', 'bad%string;value');

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    /** ensure accept works when using central-auth */
    public function testOnKernelRequestWithAuthBaseUsesAuthCookieName(): void {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $domainManager = new DomainManager(true, 'auth.example.com');
        $listener = new AcceptListener($cache, $domainManager);
        $listener->setLogger($this->createMockLogger());

        $cacheItem = $this->createMockItem('user2');
        $cache->expects($this->atLeastOnce())->method('hasItem')
            ->with('cookie_xyz789')->willReturn(true);
        $cache->expects($this->atLeastOnce())->method('getItem')
            ->with('cookie_xyz789')->willReturn($cacheItem);

        $request = Request::create('https://example.com/');
        $request->cookies->set('__Http-Domain-Preauth', 'xyz789');

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame('hi user2', $response->getContent());
    }

    /** cookie for direct-auth when using auth-subdomain */
    public function testOnKernelRequestWithWrongCookie(): void {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $domainManager = new DomainManager(true, 'auth.example.com');
        $listener = new AcceptListener($cache, $domainManager);
        $listener->setLogger($this->createMockLogger($this->never()));

        $cache->expects($this->never())->method('hasItem');

        $request = Request::create('https://example.com/');
        $request->cookies->set('__Host-Http-Preauth', 'abc123');

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNull($response);
    }

    /** cookie for auth-subdomain when using direct-auth */
    public function testOnKernelRequestWithWrongCookieAlt(): void {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $domainManager = new DomainManager(false, '');
        $listener = new AcceptListener($cache, $domainManager);
        $listener->setLogger($this->createMockLogger($this->never()));

        $cache->expects($this->never())->method('hasItem');

        $request = Request::create('https://example.com/');
        $request->cookies->set('__Http-Domain-Preauth', 'abc123');

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNull($response);
    }

    /** empty cookie should be quietly ignored */
    public function testOnKernelRequestWithEmptyCookieValue(): void {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects($this->never())->method('hasItem');
        $domainManager = new DomainManager(false, '');
        $listener = new AcceptListener($cache, $domainManager);
        $listener->setLogger($this->createMockLogger($this->never()));

        $request = Request::create('https://example.com/');
        $request->cookies->set('__Host-Http-Preauth', '');

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }
}
