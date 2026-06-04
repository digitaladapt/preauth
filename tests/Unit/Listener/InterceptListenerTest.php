<?php
declare(strict_types=1);

namespace App\Tests\Unit\Listener;

use App\ConfigBag;
use App\Listener\InterceptListener;
use App\Service\DomainManager;
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
use Twig\Environment;

final class InterceptListenerTest extends TestCase {
    private function createEvent(Request $request): RequestEvent {
        $kernel = $this->createMock(HttpKernelInterface::class);
        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function createConfigBag(): ConfigBag {
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
            false,
            'Error',
            'Teapot',
            'Too Many'
        );
    }

    private function createTwig(): Environment {
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->with('login.html.twig', self::anything())->willReturn('<html>login</html>');
        return $twig;
    }

    private function createListener(
        ?DomainManager $dm = null,
        ?Environment $twig = null,
        ?CacheItemPoolInterface $nonceCache = null
    ): InterceptListener {
        $config = $this->createConfigBag();
        $domainManager = $dm ?? new DomainManager(false, '');
        $twig = $twig ?? $this->createTwig();
        $listener = new InterceptListener($config, $domainManager, $twig);
        $listener->setLogger($this->createMock(LoggerInterface::class));

        if ($nonceCache) {
            $listener->setNonceCache($nonceCache);
        } else {
            $cache = $this->createMock(CacheItemPoolInterface::class);
            $item = $this->createMock(CacheItemInterface::class);
            $item->method('isHit')->willReturn(false);
            $item->method('set')->willReturnSelf();
            $item->method('expiresAfter')->willReturnSelf();
            $cache->method('getItem')->willReturn($item);
            $cache->method('save')->willReturn(true);
            $listener->setNonceCache($cache);
        }

        return $listener;
    }

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

    public function testOnKernelRequestPresentsLoginPageOnAuthSubdomain(): void {
        $dm = new DomainManager(true, 'auth.example.com');
        $listener = $this->createListener($dm);

        $request = Request::create('https://auth.example.com/');
        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('<html>login</html>', $response->getContent());
    }

    public function testOnKernelRequestPresentsLoginPageWithoutAuthSubdomain(): void {
        $dm = new DomainManager(false, '');
        $listener = $this->createListener($dm);

        $request = Request::create('https://example.com/');
        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(401, $response->getStatusCode());
    }

    public function testOnKernelRequestPrunesInvalidCookie(): void {
        $dm = new DomainManager(false, '');
        $listener = $this->createListener($dm);

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

    public function testOnKernelRequestDoesNotPruneCookieWhenNotPresent(): void {
        $dm = new DomainManager(false, '');
        $listener = $this->createListener($dm);

        $request = Request::create('https://example.com/');
        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertCount(0, $response->headers->getCookies());
    }

    public function testOnKernelRequestWithAuthBaseUsesAuthCookieName(): void {
        $dm = new DomainManager(true, 'auth.example.com');
        $listener = $this->createListener($dm);

        $request = Request::create('https://auth.example.com/');
        $request->cookies->set('__Http-Domain-Preauth', 'invalid');

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        $cookies = $response->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertSame('__Http-Domain-Preauth', $cookies[0]->getName());
    }
}
