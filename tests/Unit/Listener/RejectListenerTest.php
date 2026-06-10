<?php
declare(strict_types=1);

namespace App\Tests\Unit\Listener;

use App\ConfigBag;
use App\Listener\RejectListener;
use App\Utilities;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;
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

final class RejectListenerTest extends TestCase {
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
            'Error',
            'Teapot!',
            'Too Many'
        );
    }

    private function createLimiter(int $remainingTokens): LimiterInterface {
        $limit = $this->createMock(RateLimit::class);
        $limit->expects($this->once())->method('getRemainingTokens')
            ->willReturn($remainingTokens);

        $limiter = $this->createMock(LimiterInterface::class);
        $limiter->expects($this->once())->method('consume')->with(0)
            ->willReturn($limit);
        return $limiter;
    }

    /** when not blocked, expect no response */
    public function testOnKernelRequestWhenNotBlocked(): void {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->never())->method('render');
        $config = $this->createConfigBag();
        $rateLimiter = $this->createMock(RateLimiterFactoryInterface::class);
        $rateLimiter->expects($this->once())->method('create')
            ->willReturn($this->createLimiter(5));

        $listener = new RejectListener($config, $twig, $rateLimiter);
        $listener->setLogger($this->createStub(LoggerInterface::class));

        $request = Request::create('https://example.com/');
        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    /** when blocked, expect too-many response */
    public function testOnKernelRequestWhenBlocked(): void {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())->method('render')
            ->with('error.html.twig')->willReturn('<html>blocked</html>');

        $config = $this->createConfigBag();
        $rateLimiter = $this->createMock(RateLimiterFactoryInterface::class);
        $rateLimiter->expects($this->once())->method('create')
            ->willReturn($this->createLimiter(0));

        $listener = new RejectListener($config, $twig, $rateLimiter);
        $listener->setLogger($this->createStub(LoggerInterface::class));

        $request = Request::create('https://example.com/');
        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('<html>blocked</html>', $response->getContent());
    }

    /** when blocked with teapot expect teapot response */
    public function testOnKernelRequestWhenBlockedWithTeapot(): void {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())->method('render')
            ->with('error.html.twig')->willReturn('<html>teapot</html>');

        $config = $this->createConfigBag(true);
        $rateLimiter = $this->createMock(RateLimiterFactoryInterface::class);
        $rateLimiter->expects($this->once())->method('create')
            ->willReturn($this->createLimiter(0));

        $listener = new RejectListener($config, $twig, $rateLimiter);
        $listener->setLogger($this->createStub(LoggerInterface::class));

        $request = Request::create('https://example.com/');
        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(418, $response->getStatusCode());
    }
}
