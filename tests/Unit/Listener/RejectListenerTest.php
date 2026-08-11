<?php

declare(strict_types=1);

namespace App\Tests\Unit\Listener;

use App\Listener\RejectListener;
use App\Service\DomainManager;
use App\Tests\Support\ListenerTestHelper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class RejectListenerTest extends TestCase
{
    use ListenerTestHelper;

    private function makeListener(
        bool $teapot = true,
        int $remainingTokens = 5,
    ): RejectListener {
        $listener = new RejectListener(
            $this->makeConfig(teapot: $teapot),
            $this->makeTwig(),
            $this->makeRateLimiterFactory($remainingTokens),
        );
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

    public function testBlockedRequestReturnsTeapotWhenTeapotEnabled(): void
    {
        $listener = $this->makeListener(teapot: true, remainingTokens: 0);

        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '1.2.3.4']);
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        $response = $event->getResponse();
        self::assertSame(Response::HTTP_I_AM_A_TEAPOT, $response->getStatusCode());
        self::assertSame('text/html', $response->headers->get('Content-Type'));
    }

    public function testBlockedRequestReturnsTooManyRequestsWhenTeapotDisabled(): void
    {
        $listener = $this->makeListener(teapot: false, remainingTokens: 0);

        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '1.2.3.4']);
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        $response = $event->getResponse();
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        self::assertSame('text/html', $response->headers->get('Content-Type'));
    }

    public function testUnblockedRequestSetsNoResponse(): void
    {
        $listener = $this->makeListener(remainingTokens: 5);

        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '1.2.3.4']);
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        // consume(0) with remaining tokens > 0 should not block
        self::assertFalse($event->hasResponse());
    }

    public function testBlockedResponseContainsErrorTemplateContent(): void
    {
        $listener = $this->makeListener(teapot: true, remainingTokens: 0);

        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '1.2.3.4']);
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        $content = $event->getResponse()->getContent();
        // Twig escapes the apostrophe in "I'm a teapot" to &#039;
        self::assertStringContainsString('a teapot', $content);
        self::assertStringContainsString('I refuse to brew coffee', $content);
    }
}
