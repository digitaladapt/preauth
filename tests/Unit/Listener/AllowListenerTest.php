<?php
declare(strict_types=1);

namespace App\Tests\Unit\Listener;

use App\ConfigBag;
use App\Listener\AllowListener;
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

final class AllowListenerTest extends TestCase {
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

    private function createConfigBag(int $ipTtl = 1800): ConfigBag {
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
            $ipTtl,
            false,
            'Error',
            'Teapot',
            'Too Many'
        );
    }

    public function testOnKernelRequestWithValidIp(): void {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $config = $this->createConfigBag(1800);
        $listener = new AllowListener($cache, $config);
        $listener->setLogger($this->createMock(LoggerInterface::class));

        $cacheItem = $this->createMockItem('ip_192.168.1.1', 'user1', true);
        $cache->method('hasItem')->with('ip_192.168.1.1')->willReturn(true);
        $cache->method('getItem')->with('ip_192.168.1.1')->willReturn($cacheItem);

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

    public function testOnKernelRequestWithIpTtlZero(): void {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $config = $this->createConfigBag(0);
        $listener = new AllowListener($cache, $config);
        $listener->setLogger($this->createMock(LoggerInterface::class));

        $request = Request::create('https://example.com/');
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testOnKernelRequestWithIpTtlNull(): void {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $config = $this->createConfigBag(0);
        $listener = new AllowListener($cache, $config);
        $listener->setLogger($this->createMock(LoggerInterface::class));

        $request = Request::create('https://example.com/');
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testOnKernelRequestWithUnknownIp(): void {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $config = $this->createConfigBag(1800);
        $listener = new AllowListener($cache, $config);
        $listener->setLogger($this->createMock(LoggerInterface::class));

        $cache->method('hasItem')->with('ip_192.168.1.1')->willReturn(false);

        $request = Request::create('https://example.com/');
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }
}
