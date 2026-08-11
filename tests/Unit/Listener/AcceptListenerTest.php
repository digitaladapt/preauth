<?php

declare(strict_types=1);

namespace App\Tests\Unit\Listener;

use App\Listener\AcceptListener;
use App\Service\DomainManager;
use App\Tests\Support\TotpTestHelper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class AcceptListenerTest extends TestCase
{
    use TotpTestHelper;

    private const string COOKIE_NAME = '__Host-Http-Preauth';
    private const string AUTH_COOKIE_NAME = '__Http-Domain-Preauth';

    private function makeListener(ArrayAdapter $pool, DomainManager $domainManager): AcceptListener
    {
        $listener = new AcceptListener($pool, $domainManager);
        $listener->setLogger(new NullLogger());
        return $listener;
    }

    private function makeEvent(Request $request): RequestEvent
    {
        return new RequestEvent(
            $this->createStub(\Symfony\Component\HttpKernel\HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    /* ── valid cookie session ─────────────────────────────────────────── */

    public function testValidCookieSetsResponseWithRemoteUser(): void
    {
        $pool = new ArrayAdapter();
        $ulid = '01HXY1234567890ABCDEFGHIJK';
        $item = $pool->getItem('cookie_' . $ulid);
        $item->set('alice');
        $pool->save($item);

        $domainManager = new DomainManager(false, '');
        $listener = $this->makeListener($pool, $domainManager);

        $request = Request::create('/', 'GET');
        $request->cookies->set(self::COOKIE_NAME, $ulid);

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        $response = $event->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('alice', $response->headers->get('Remote-User'));
        self::assertSame('text/plain', $response->headers->get('Content-Type'));
    }

    public function testValidCookieUsesAuthCookieNameWhenUsingCentralAuth(): void
    {
        $pool = new ArrayAdapter();
        $ulid = '01HXY1234567890ABCDEFGHIJK';
        $item = $pool->getItem('cookie_' . $ulid);
        $item->set('bob');
        $pool->save($item);

        $domainManager = new DomainManager(true, 'auth.example.com');
        $listener = $this->makeListener($pool, $domainManager);

        $request = Request::create('/', 'GET');
        $request->cookies->set(self::AUTH_COOKIE_NAME, $ulid);

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame('bob', $event->getResponse()->headers->get('Remote-User'));
    }

    /* ── negative cases ───────────────────────────────────────────────── */

    public function testNoCookieSetsNoResponse(): void
    {
        $pool = new ArrayAdapter();
        $domainManager = new DomainManager(false, '');
        $listener = $this->makeListener($pool, $domainManager);

        $event = $this->makeEvent(Request::create('/', 'GET'));
        $listener->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testCookieWithoutSessionSetsNoResponse(): void
    {
        $pool = new ArrayAdapter();
        $domainManager = new DomainManager(false, '');
        $listener = $this->makeListener($pool, $domainManager);

        $request = Request::create('/', 'GET');
        $request->cookies->set(self::COOKIE_NAME, 'unknown-ulid');

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testEmptyCookieValueSetsNoResponse(): void
    {
        $pool = new ArrayAdapter();
        $domainManager = new DomainManager(false, '');
        $listener = $this->makeListener($pool, $domainManager);

        // cookies->set with empty string
        $request = Request::create('/', 'GET');
        $request->cookies->set(self::COOKIE_NAME, '');

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        // empty cookie value should not be treated as a valid session
        self::assertFalse($event->hasResponse());
    }
}
