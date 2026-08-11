<?php

declare(strict_types=1);

namespace App\Tests\Unit\Listener;

use App\ConfigBag;
use App\Listener\AllowListener;
use App\Tests\Support\TotpTestHelper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class AllowListenerTest extends TestCase
{
    use TotpTestHelper;

    private function makeListener(ArrayAdapter $pool, ConfigBag $config): AllowListener
    {
        $listener = new AllowListener($pool, $config);
        $listener->setLogger(new NullLogger());
        return $listener;
    }

    private function makeEvent(Request $request): RequestEvent
    {
        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    public function testValidIpSessionSetsResponseWithRemoteUser(): void
    {
        $pool = new ArrayAdapter();
        $item = $pool->getItem('ip_1.2.3.4');
        $item->set('carol');
        $pool->save($item);

        $config = $this->makeConfig(ipTtl: 1800);
        $listener = $this->makeListener($pool, $config);

        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '1.2.3.4']);
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        $response = $event->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('carol', $response->headers->get('Remote-User'));
        self::assertSame('text/plain', $response->headers->get('Content-Type'));
    }

    public function testNoIpSessionSetsNoResponse(): void
    {
        $pool = new ArrayAdapter();
        $config = $this->makeConfig(ipTtl: 1800);
        $listener = $this->makeListener($pool, $config);

        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '9.9.9.9']);
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testIpAccessDisabledSetsNoResponse(): void
    {
        $pool = new ArrayAdapter();
        // even though there's a stored session, ip access is disabled
        $item = $pool->getItem('ip_1.2.3.4');
        $item->set('carol');
        $pool->save($item);

        $config = $this->makeConfig(ipTtl: 0);
        $listener = $this->makeListener($pool, $config);

        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '1.2.3.4']);
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testIpAccessDisabledDoesNotCheckCache(): void
    {
        $pool = new ArrayAdapter();
        $config = $this->makeConfig(ipTtl: 0);
        $listener = $this->makeListener($pool, $config);

        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '1.2.3.4']);
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        // when disabled, nothing should have been written/read as a session
        self::assertFalse($event->hasResponse());
        self::assertFalse($pool->hasItem('ip_1.2.3.4'));
    }
}
