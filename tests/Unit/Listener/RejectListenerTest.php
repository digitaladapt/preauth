<?php
declare(strict_types=1);

namespace App\Tests\Unit\Listener;

use App\ConfigBag;
use App\Listener\RejectListener;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;
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
        $kernel = $this->createMock(HttpKernelInterface::class);
        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function createConfigBag(bool $teapot = false): ConfigBag {
        $clock = $this->createMock(ClockInterface::class);
        $utilities = $this->createMock(\App\Utilities::class);
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
        $limit->method('getRemainingTokens')->willReturn($remainingTokens);

        $limiter = $this->createMock(LimiterInterface::class);
        $limiter->method('consume')->with(0)->willReturn($limit);
        return $limiter;
    }

    public function testOnKernelRequestWhenNotBlocked(): void {
        $twig = $this->createMock(Environment::class);
        $config = $this->createConfigBag();
        $rateLimiter = $this->createMock(RateLimiterFactoryInterface::class);
        $rateLimiter->method('create')->willReturn($this->createLimiter(5));

        $listener = new RejectListener($config, $twig, $rateLimiter);
        $listener->setLogger($this->createMock(LoggerInterface::class));

        $request = Request::create('https://example.com/');
        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testOnKernelRequestWhenBlocked(): void {
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->with('error.html.twig')->willReturn('<html>blocked</html>');

        $config = $this->createConfigBag();
        $rateLimiter = $this->createMock(RateLimiterFactoryInterface::class);
        $rateLimiter->method('create')->willReturn($this->createLimiter(0));

        $listener = new RejectListener($config, $twig, $rateLimiter);
        $listener->setLogger($this->createMock(LoggerInterface::class));

        $request = Request::create('https://example.com/');
        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('<html>blocked</html>', $response->getContent());
    }

    public function testOnKernelRequestWhenBlockedWithTeapot(): void {
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->with('error.html.twig')->willReturn('<html>teapot</html>');

        $config = $this->createConfigBag(true);
        $rateLimiter = $this->createMock(RateLimiterFactoryInterface::class);
        $rateLimiter->method('create')->willReturn($this->createLimiter(0));

        $listener = new RejectListener($config, $twig, $rateLimiter);
        $listener->setLogger($this->createMock(LoggerInterface::class));

        $request = Request::create('https://example.com/');
        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(418, $response->getStatusCode());
    }
}
