<?php
declare(strict_types=1);

namespace App\Tests\Unit\Listener;

use App\Listener\AcceptListener;
use App\Service\DomainManager;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class AcceptListenerTest extends TestCase {
    private function createEvent(Request $request): RequestEvent {
        $kernel = $this->createMock(HttpKernelInterface::class);
        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function createMockItem(string $key, mixed $value = null, bool $isHit = true): CacheItemInterface {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('getKey')->willReturn($key);
        $item->method('get')->willReturn($value);
        $item->method('isHit')->willReturn($isHit);
        return $item;
    }

    public function testOnKernelRequestWithValidCookie(): void {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $domainManager = new DomainManager(false, '');
        $listener = new AcceptListener($cache, $domainManager);
        $listener->setLogger($this->createMock(LoggerInterface::class));

        $cacheItem = $this->createMockItem('cookie_abc123', 'user1', true);
        $cache->method('hasItem')->with('cookie_abc123')->willReturn(true);
        $cache->method('getItem')->with('cookie_abc123')->willReturn($cacheItem);

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

    public function testOnKernelRequestWithNoCookie(): void {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $domainManager = new DomainManager(false, '');
        $listener = new AcceptListener($cache, $domainManager);
        $listener->setLogger($this->createMock(LoggerInterface::class));

        $request = Request::create('https://example.com/');
        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testOnKernelRequestWithInvalidCookie(): void {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $domainManager = new DomainManager(false, '');
        $listener = new AcceptListener($cache, $domainManager);
        $listener->setLogger($this->createMock(LoggerInterface::class));

        $cache->method('hasItem')->with('cookie_badcookie')->willReturn(false);

        $request = Request::create('https://example.com/');
        $request->cookies->set('__Host-Http-Preauth', 'badcookie');

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testOnKernelRequestWithAuthBaseUsesAuthCookieName(): void {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $domainManager = new DomainManager(true, 'auth.example.com');
        $listener = new AcceptListener($cache, $domainManager);
        $listener->setLogger($this->createMock(LoggerInterface::class));

        $cacheItem = $this->createMockItem('cookie_xyz789', 'user2', true);
        $cache->method('hasItem')->with('cookie_xyz789')->willReturn(true);
        $cache->method('getItem')->with('cookie_xyz789')->willReturn($cacheItem);

        $request = Request::create('https://example.com/');
        $request->cookies->set('__Http-Domain-Preauth', 'xyz789');

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame('hi user2', $response->getContent());
    }

    public function testOnKernelRequestWithEmptyCookieValue(): void {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $domainManager = new DomainManager(false, '');
        $listener = new AcceptListener($cache, $domainManager);
        $listener->setLogger($this->createMock(LoggerInterface::class));

        $request = Request::create('https://example.com/');
        $request->cookies->set('__Host-Http-Preauth', '');

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }
}
