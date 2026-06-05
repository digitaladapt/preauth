<?php
declare(strict_types=1);

namespace App\Tests\Unit\Listener;

use App\ConfigBag;
use App\Listener\AllowListener;
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

final class AllowListenerTest extends TestCase {
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

    private function createConfigBag(int $ipTtl = 1800): ConfigBag {
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
            $ipTtl,
            false,
            'Error',
            'Teapot',
            'Too Many'
        );
    }

    /** ip-ttl enabled, valid session for client-ip */
    public function testOnKernelRequestWithValidIp(): void {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $config = $this->createConfigBag(1800);
        $listener = new AllowListener($cache, $config);
        $listener->setLogger($this->createMockLogger());

        $cacheItem = $this->createMockItem('user1');
        $cache->expects($this->atLeastOnce())->method('hasItem')
            ->with('ip_192.168.1.1')->willReturn(true);
        $cache->expects($this->atLeastOnce())->method('getItem')
            ->with('ip_192.168.1.1')->willReturn($cacheItem);

        $request = Request::create('https://example.com/');
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('hi user1', $response->getContent());
        self::assertSame('user1', $response->headers->get('Remote-User'));
    }

    /** ip-ttl disabled (default), should not even check the cache */
    public function testOnKernelRequestWithIpTtlZero(): void {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $config = $this->createConfigBag(0);
        $listener = new AllowListener($cache, $config);
        $listener->setLogger($this->createMockLogger($this->never()));

        $cache->expects($this->never())->method('hasItem');

        $request = Request::create('https://example.com/');
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    /** ip-ttl enabled, but client-ip does not have a session */
    public function testOnKernelRequestWithUnknownIp(): void {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $config = $this->createConfigBag(1800);
        $listener = new AllowListener($cache, $config);
        $listener->setLogger($this->createMockLogger($this->never()));

        $cache->expects($this->atLeastOnce())->method('hasItem')
            ->with('ip_192.168.1.1')->willReturn(false);

        $request = Request::create('https://example.com/');
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }
}
