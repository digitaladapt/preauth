<?php

declare(strict_types=1);

namespace App\Tests\Unit\Listener;

use App\ConfigBag;
use App\Listener\AcceptListener;
use App\Service\DomainManager;
use App\Tests\Support\TotpTestHelper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class AcceptListenerTest extends TestCase
{
    use TotpTestHelper;

    private const string COOKIE_NAME = '__Host-Http-Preauth';
    private const string AUTH_COOKIE_NAME = '__Http-Domain-Preauth';

    private function makeListener(
        ArrayAdapter $pool,
        DomainManager $domainManager,
        ?ConfigBag $config = null,
    ): AcceptListener {
        $listener = new AcceptListener($pool, $domainManager, $config ?? $this->makeConfig());
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

    /* ── valid cookie session ─────────────────────────────────────────── */

    public function test_valid_cookie_sets_response_with_remote_user(): void
    {
        $pool = new ArrayAdapter();
        $ulid = '01HXY1234567890ABCDEFGHIJK';
        $item = $pool->getItem('cookie_'.$ulid);
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

    public function test_valid_cookie_uses_auth_cookie_name_when_using_central_auth(): void
    {
        $pool = new ArrayAdapter();
        $ulid = '01HXY1234567890ABCDEFGHIJK';
        $item = $pool->getItem('cookie_'.$ulid);
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

    public function test_no_cookie_sets_no_response(): void
    {
        $pool = new ArrayAdapter();
        $domainManager = new DomainManager(false, '');
        $listener = $this->makeListener($pool, $domainManager);

        $event = $this->makeEvent(Request::create('/', 'GET'));
        $listener->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function test_cookie_without_session_sets_no_response(): void
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

    public function test_empty_cookie_value_sets_no_response(): void
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

    /* ── Remote-User header modes ─────────────────────────────────────── */

    public function test_remote_user_session_mode_sends_session_id(): void
    {
        $pool = new ArrayAdapter();
        $ulid = '01HXY1234567890ABCDEFGHIJK';
        $item = $pool->getItem('cookie_'.$ulid);
        $item->set('alice');
        $pool->save($item);

        $domainManager = new DomainManager(false, '');
        $listener = $this->makeListener(
            $pool,
            $domainManager,
            $this->makeConfig(remoteUserMode: 'session'),
        );

        $request = Request::create('/', 'GET');
        $request->cookies->set(self::COOKIE_NAME, $ulid);

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame('alice', $event->getResponse()->headers->get('Remote-User'));
    }

    public function test_remote_user_static_mode_sends_fixed_value(): void
    {
        $pool = new ArrayAdapter();
        $ulid = '01HXY1234567890ABCDEFGHIJK';
        $item = $pool->getItem('cookie_'.$ulid);
        $item->set('alice');
        $pool->save($item);

        $domainManager = new DomainManager(false, '');
        $listener = $this->makeListener(
            $pool,
            $domainManager,
            $this->makeConfig(remoteUserMode: 'static', remoteUserStatic: 'authenticated'),
        );

        $request = Request::create('/', 'GET');
        $request->cookies->set(self::COOKIE_NAME, $ulid);

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame('authenticated', $event->getResponse()->headers->get('Remote-User'));
    }

    public function test_remote_user_mapped_mode_sends_mapped_value(): void
    {
        $pool = new ArrayAdapter();
        $ulid = '01HXY1234567890ABCDEFGHIJK';
        $item = $pool->getItem('cookie_'.$ulid);
        $item->set('alice');
        $pool->save($item);

        $domainManager = new DomainManager(false, '');
        $listener = $this->makeListener(
            $pool,
            $domainManager,
            $this->makeConfig(remoteUserMode: 'mapped', remoteUserMap: 'alice:admin,bob:user'),
        );

        $request = Request::create('/', 'GET');
        $request->cookies->set(self::COOKIE_NAME, $ulid);

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame('admin', $event->getResponse()->headers->get('Remote-User'));
    }

    public function test_remote_user_mapped_mode_falls_back_to_session_id_when_not_in_map(): void
    {
        $pool = new ArrayAdapter();
        $ulid = '01HXY1234567890ABCDEFGHIJK';
        $item = $pool->getItem('cookie_'.$ulid);
        $item->set('unknown_user');
        $pool->save($item);

        $domainManager = new DomainManager(false, '');
        $listener = $this->makeListener(
            $pool,
            $domainManager,
            $this->makeConfig(remoteUserMode: 'mapped', remoteUserMap: 'alice:admin'),
        );

        $request = Request::create('/', 'GET');
        $request->cookies->set(self::COOKIE_NAME, $ulid);

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame('unknown_user', $event->getResponse()->headers->get('Remote-User'));
    }

    public function test_remote_user_none_mode_omits_header(): void
    {
        $pool = new ArrayAdapter();
        $ulid = '01HXY1234567890ABCDEFGHIJK';
        $item = $pool->getItem('cookie_'.$ulid);
        $item->set('alice');
        $pool->save($item);

        $domainManager = new DomainManager(false, '');
        $listener = $this->makeListener(
            $pool,
            $domainManager,
            $this->makeConfig(remoteUserMode: 'none'),
        );

        $request = Request::create('/', 'GET');
        $request->cookies->set(self::COOKIE_NAME, $ulid);

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertFalse($event->getResponse()->headers->has('Remote-User'));
    }
}
