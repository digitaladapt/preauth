<?php
declare(strict_types=1);

namespace App\Tests\Unit\Listener;

use App\Data\Payload;
use App\Enum\Scope;
use App\Listener\LoginListener;
use App\Service\DomainManager;
use App\Service\LoginInterface;
use App\Tests\Support\ListenerTestHelper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class LoginListenerTest extends TestCase {
    use ListenerTestHelper;

    private const string HEADER_NAME = 'X-Preauth';

    private function makeListener(
        ?LoginInterface $loginManager = null,
        ?DomainManager $domainManager = null,
        ?int $rateLimitRemaining = 5,
    ): LoginListener {
        $listener = new LoginListener(
            $this->makeTwig(),
            $this->makeRateLimiterFactory($rateLimitRemaining ?? 5),
            $domainManager ?? new DomainManager(false, ''),
            $loginManager ?? $this->createStub(LoginInterface::class),
            $this->makeConfig(),
        );
        $listener->setLogger(new NullLogger());
        $listener->setNonceCache(new ArrayAdapter());
        return $listener;
    }

    private function makeEvent(Request $request): RequestEvent {
        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    /** Build a base64url-encoded X-Preauth header value for a payload. */
    private function encodePayload(array $data): string {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /* ── no login attempt ─────────────────────────────────────────────── */

    public function testNoHeaderAndNoPostReturnsEarlyWithoutResponse(): void {
        $listener = $this->makeListener();

        $request = Request::create('https://example.com/', 'GET');
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testPostToNonAuthSubdomainReturnsEarlyWithoutResponse(): void {
        // POST only counts as a login attempt when on the auth subdomain
        $domainManager = new DomainManager(true, 'auth.example.com');
        $listener = $this->makeListener(domainManager: $domainManager);

        $request = Request::create('https://app.example.com/', 'POST');
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    /* ── successful login via header ──────────────────────────────────── */

    public function testSuccessfulLoginViaHeaderSetsResponseFromManager(): void {
        $expected = new Response('hi alice', 200, ['Remote-User' => 'alice']);
        $loginManager = $this->createStub(LoginInterface::class);
        $loginManager->method('checkToken')->willReturn($expected);

        $listener = $this->makeListener(loginManager: $loginManager);

        $payload = $this->encodePayload([
            'id' => 'alice', 'token' => '123456', 'nonce' => 'nonce-1', 'json' => true,
        ]);
        $request = Request::create('https://example.com/', 'GET');
        $request->headers->set(self::HEADER_NAME, $payload);

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame($expected, $event->getResponse());
    }

    public function testSuccessfulLoginViaPostToAuthSubdomain(): void {
        $expected = new Response('hi bob', 303, ['Location' => '/']);
        $loginManager = $this->createStub(LoginInterface::class);
        $loginManager->method('checkToken')->willReturn($expected);

        $domainManager = new DomainManager(true, 'auth.example.com');
        $listener = $this->makeListener(loginManager: $loginManager, domainManager: $domainManager);

        $request = Request::create('https://auth.example.com/', 'POST', [
            'username' => 'bob', 'totp' => '654321', 'nonce' => 'nonce-2',
        ]);

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame($expected, $event->getResponse());
    }

    /* ── failed login ─────────────────────────────────────────────────── */

    public function testFailedLoginReturnsJsonErrorWithNewNonce(): void {
        $loginManager = $this->createStub(LoginInterface::class);
        $loginManager->method('checkToken')->willReturn(null);

        $listener = $this->makeListener(loginManager: $loginManager);

        $payload = $this->encodePayload([
            'id' => 'alice', 'token' => 'wrong', 'nonce' => 'nonce-1', 'json' => true,
        ]);
        $request = Request::create('https://example.com/', 'GET');
        $request->headers->set(self::HEADER_NAME, $payload);

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        $response = $event->getResponse();
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        $body = json_decode($response->getContent(), true);
        // the TotpTestHelper::makeConfig default errorMessage is 'Error'
        self::assertSame('Error', $body['message']);
        self::assertNotEmpty($body['nonce']);
        self::assertFalse($body['post']);
        // username is echoed back (sanitized via makeCacheKey)
        self::assertSame('alice', $body['username']);
    }

    public function testFailedLoginHtmlResponseWhenJsonFalse(): void {
        $loginManager = $this->createStub(LoginInterface::class);
        $loginManager->method('checkToken')->willReturn(null);

        $listener = $this->makeListener(loginManager: $loginManager);

        $payload = $this->encodePayload([
            'id' => 'alice', 'token' => 'wrong', 'nonce' => 'nonce-1', 'json' => false,
        ]);
        $request = Request::create('https://example.com/', 'GET');
        $request->headers->set(self::HEADER_NAME, $payload);

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('text/html', $response->headers->get('Content-Type'));
        self::assertStringContainsString('<form', $response->getContent());
    }

    public function testFailedLoginOnAuthSubdomainUsesPostForm(): void {
        $loginManager = $this->createStub(LoginInterface::class);
        $loginManager->method('checkToken')->willReturn(null);

        $domainManager = new DomainManager(true, 'auth.example.com');
        $listener = $this->makeListener(
            loginManager: $loginManager,
            domainManager: $domainManager,
        );

        $payload = $this->encodePayload([
            'id' => 'alice', 'token' => 'wrong', 'nonce' => 'nonce-1', 'json' => false,
        ]);
        $request = Request::create('https://auth.example.com/', 'GET');
        $request->headers->set(self::HEADER_NAME, $payload);

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        $content = $event->getResponse()->getContent();
        self::assertStringContainsString('method="post"', $content);
    }

    /* ── rate-limited (blocked) login ─────────────────────────────────── */

    public function testRateLimitedLoginReturnsTeapotWhenTeapotEnabled(): void {
        $loginManager = $this->createStub(LoginInterface::class);
        $loginManager->method('checkToken')->willReturn(null);

        // limiter with 0 remaining tokens -> blocked
        $listener = $this->makeListener(
            loginManager: $loginManager,
            rateLimitRemaining: 0,
        );

        $payload = $this->encodePayload([
            'id' => 'alice', 'token' => 'wrong', 'nonce' => 'nonce-1', 'json' => true,
        ]);
        $request = Request::create('https://example.com/', 'GET');
        $request->headers->set(self::HEADER_NAME, $payload);

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertSame(Response::HTTP_I_AM_A_TEAPOT, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        // the TotpTestHelper::makeConfig default teapotTitle is 'Teapot'
        self::assertSame('Teapot', $body['message']);
    }

    public function testRateLimitedLoginReturnsTooManyRequestsWhenTeapotDisabled(): void {
        $loginManager = $this->createStub(LoginInterface::class);
        $loginManager->method('checkToken')->willReturn(null);

        $listener = new LoginListener(
            $this->makeTwig(),
            $this->makeRateLimiterFactory(0),
            new DomainManager(false, ''),
            $loginManager,
            $this->makeConfig(teapot: false),
        );
        $listener->setLogger(new NullLogger());
        $listener->setNonceCache(new ArrayAdapter());

        $payload = $this->encodePayload([
            'id' => 'alice', 'token' => 'wrong', 'nonce' => 'nonce-1', 'json' => true,
        ]);
        $request = Request::create('https://example.com/', 'GET');
        $request->headers->set(self::HEADER_NAME, $payload);

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        // teapot disabled, so tooManyTitle is used; helper default is 'Too Many'
        self::assertSame('Too Many', $body['message']);
    }

    /* ── invalid payload handling ─────────────────────────────────────── */

    public function testInvalidHeaderPayloadStillRecordsFailureAndResponds(): void {
        $loginManager = $this->createMock(LoginInterface::class);
        // checkToken should not be called with a null payload
        $loginManager->expects(self::never())->method('checkToken');

        $listener = $this->makeListener(loginManager: $loginManager);

        // an un-decodable header value
        $request = Request::create('https://example.com/', 'GET');
        $request->headers->set(self::HEADER_NAME, '!!!not-valid-base64!!!');

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        // Payload::decode returns null, so checkToken is skipped, but a
        // failure response is still produced (the rate limiter is consulted)
        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_UNAUTHORIZED, $event->getResponse()->getStatusCode());
    }

    public function testPostWithoutRequiredFieldsDoesNotAttemptLogin(): void {
        $loginManager = $this->createMock(LoginInterface::class);
        $loginManager->expects(self::never())->method('checkToken');

        $domainManager = new DomainManager(true, 'auth.example.com');
        $listener = $this->makeListener(
            loginManager: $loginManager,
            domainManager: $domainManager,
        );

        // POST to auth subdomain but missing the required fields
        $request = Request::create('https://auth.example.com/', 'POST', ['username' => 'only-user']);

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        // Payload::load returns null (missing totp & nonce), so it falls through
        // to the failure path and produces a response
        self::assertTrue($event->hasResponse());
    }
}
