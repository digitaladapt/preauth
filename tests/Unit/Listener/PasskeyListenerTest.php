<?php

declare(strict_types=1);

namespace App\Tests\Unit\Listener;

use App\AppConstants;
use App\Data\PasskeyCredential;
use App\Enum\Scope;
use App\Listener\PasskeyListener;
use App\Service\DomainInterface;
use App\Service\PasskeyInterface;
use App\Service\PasskeyPolicyInterface;
use App\Service\SessionIssuerInterface;
use App\Tests\Support\TotpTestHelper;
use App\Trait\StringTrait;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * The listener is where several security properties are enforced at once, so
 * each is asserted separately: which requests it claims, what it does with
 * ones it cannot serve, and — crucially — that a failure looks exactly like a
 * wrong TOTP code.
 */
final class PasskeyListenerTest extends TestCase
{
    use StringTrait;
    use TotpTestHelper;

    private const string AUTH_HOST = 'auth.example.com';

    private function makeListener(
        ?PasskeyInterface $passkeys = null,
        bool $available = true,
        ?SessionIssuerInterface $sessionIssuer = null,
        ?DomainInterface $domainManager = null,
        int $beginLimit = 30,
    ): PasskeyListener {
        $policy = $this->createStub(PasskeyPolicyInterface::class);
        $policy->method('isAvailableFor')->willReturn($available);

        $domainManager ??= $this->authDomainManager();

        $listener = new PasskeyListener(
            new RateLimiterFactory(['id' => 'passkey_begin_burst', 'policy' => 'sliding_window', 'limit' => $beginLimit, 'interval' => '60 seconds'], new InMemoryStorage()),
            new RateLimiterFactory(['id' => 'login_limiter', 'policy' => 'sliding_window', 'limit' => 2, 'interval' => '60 seconds'], new InMemoryStorage()),
            $passkeys ?? $this->createStub(PasskeyInterface::class),
            $policy,
            $sessionIssuer ?? $this->createStub(SessionIssuerInterface::class),
            $domainManager,
            $this->makeConfig(),
        );
        $listener->setLogger(new NullLogger());
        $listener->setNonceCache(new ArrayAdapter());

        return $listener;
    }

    private function authDomainManager(): DomainInterface
    {
        $manager = $this->createStub(DomainInterface::class);
        $manager->method('getAuthSubdomain')->willReturn(self::AUTH_HOST);
        $manager->method('authBase')->willReturn('example.com');

        return $manager;
    }

    private function makeEvent(Request $request): RequestEvent
    {
        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    /**
     * @param array<string,mixed> $body
     */
    private function ceremonyRequest(string $operation, string $host = self::AUTH_HOST, array $body = []): Request
    {
        return Request::create(
            "https://$host/",
            'POST',
            [],
            [],
            [],
            ['HTTP_X_PREAUTH_PASSKEY' => $operation, 'REMOTE_ADDR' => '1.2.3.4'],
            (string) json_encode($body),
        );
    }

    private function credential(string $identity = 'lyra'): PasskeyCredential
    {
        return new PasskeyCredential(
            CredentialRecord::create(
                random_bytes(16),
                'public-key',
                ['internal'],
                'none',
                EmptyTrustPath::create(),
                Uuid::v4(),
                'KEY',
                hash('sha256', $identity, true),
                0,
                null,
                true,
                false,
                true,
            ),
            $identity,
            'Passkey abc',
            new DateTimeImmutable(),
        );
    }

    /* ── dispatch ─────────────────────────────────────────────────────── */

    /**
     * A request without the header is none of this listener's business, so it
     * must not set a response and let the normal flow continue.
     */
    public function test_a_request_without_the_header_is_ignored(): void
    {
        $listener = $this->makeListener();
        $event = $this->makeEvent(Request::create('https://'.self::AUTH_HOST.'/', 'GET'));

        $listener->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    /**
     * Only the auth subdomain hosts ceremonies; elsewhere the header is ignored
     * so this listener cannot be used to probe other hosts.
     */
    public function test_the_header_is_ignored_on_a_non_auth_host(): void
    {
        $listener = $this->makeListener();
        $event = $this->makeEvent($this->ceremonyRequest('login-begin', 'app.example.com'));

        $listener->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function test_an_unknown_operation_is_rejected(): void
    {
        $listener = $this->makeListener();
        $event = $this->makeEvent($this->ceremonyRequest('not-a-thing'));

        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    /**
     * Every header-bearing request is answered, even an unparseable one, so a
     * `fetch()` caller never receives HTML from InterceptListener.
     */
    public function test_a_malformed_body_still_gets_a_json_response(): void
    {
        $listener = $this->makeListener();
        $request = Request::create(
            'https://'.self::AUTH_HOST.'/',
            'POST',
            [],
            [],
            [],
            ['HTTP_X_PREAUTH_PASSKEY' => 'login-finish', 'REMOTE_ADDR' => '1.2.3.4'],
            'this is not json',
        );

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertIsArray(json_decode((string) $response->getContent(), true));
    }

    /* ── availability ─────────────────────────────────────────────────── */

    /**
     * When passkeys are unavailable the feature must behave as if absent: no
     * ceremony is started and nothing is written to any cache.
     */
    public function test_begin_is_inert_when_passkeys_are_unavailable(): void
    {
        $passkeys = $this->createMock(PasskeyInterface::class);
        $passkeys->expects(self::never())->method('beginLogin');

        $listener = $this->makeListener($passkeys, available: false);
        $event = $this->makeEvent($this->ceremonyRequest('login-begin'));

        $listener->onKernelRequest($event);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $event->getResponse()?->getStatusCode());
    }

    public function test_begin_login_returns_options_and_a_ceremony_id(): void
    {
        $passkeys = $this->createStub(PasskeyInterface::class);
        $passkeys->method('beginLogin')->willReturn([
            'publicKey' => ['challenge' => 'abc', 'rpId' => 'example.com'],
            'ceremonyId' => 'cid',
        ]);

        $listener = $this->makeListener($passkeys);
        $event = $this->makeEvent($this->ceremonyRequest('login-begin'));

        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertSame('cid', $decoded['ceremonyId']);
    }

    /**
     * A ceremony reply is the one browser-facing 2xx, so it carries the marker
     * that turns into the no-store policy.
     */
    public function test_ceremony_responses_carry_the_marker(): void
    {
        $passkeys = $this->createStub(PasskeyInterface::class);
        $passkeys->method('beginLogin')->willReturn(['publicKey' => [], 'ceremonyId' => 'cid']);

        $listener = $this->makeListener($passkeys);
        $event = $this->makeEvent($this->ceremonyRequest('login-begin'));

        $listener->onKernelRequest($event);

        self::assertSame('1', $event->getResponse()?->headers->get(AppConstants::PASSKEY_CEREMONY_MARKER));
    }

    /* ── registration gating ──────────────────────────────────────────── */

    /**
     * /**
     * `register-begin` deliberately has no handler here.
     *
     * A registration ceremony may only start after a valid TOTP code, which is
     * presented to `LoginManager` as part of the form submission — so
     * `LoginManager` starts it. Exposing it on this listener would hand out a
     * challenge without proving anything, which is precisely the hole the
     * design closes. This test pins that the operation is *not* honoured.
     */
    public function test_register_begin_is_not_a_listener_operation(): void
    {
        $passkeys = $this->createMock(PasskeyInterface::class);
        $passkeys->expects(self::never())->method('beginRegistration');

        $listener = $this->makeListener($passkeys);
        $event = $this->makeEvent($this->ceremonyRequest('register-begin'));

        $listener->onKernelRequest($event);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $event->getResponse()?->getStatusCode());
    }

    /* ── failures share the login budget (D3) ─────────────────────────── */

    /**
     * A failed ceremony must be indistinguishable from a wrong TOTP code: same
     * status, same shape, and it must spend the same limiter.
     */
    public function test_a_failed_ceremony_returns_401_with_a_nonce(): void
    {
        $passkeys = $this->createStub(PasskeyInterface::class);
        $passkeys->method('finishLogin')->willReturn(null);

        $listener = $this->makeListener($passkeys);
        $event = $this->makeEvent($this->ceremonyRequest('login-finish', body: ['ceremonyId' => 'cid']));

        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('nonce', $decoded);
        self::assertNotSame('', $decoded['nonce']);
    }

    /**
     * After the shared budget is exhausted the response is the same 418/429 the
     * TOTP path produces — this is what stops passkeys being an unlimited oracle.
     */
    public function test_repeated_failures_exhaust_the_shared_budget(): void
    {
        $passkeys = $this->createStub(PasskeyInterface::class);
        $passkeys->method('finishLogin')->willReturn(null);

        $listener = $this->makeListener($passkeys);

        /* the stub limiter allows 2 */
        for ($i = 0; $i < 2; ++$i) {
            $listener->onKernelRequest($this->makeEvent($this->ceremonyRequest('login-finish', body: ['ceremonyId' => 'cid'])));
        }

        $event = $this->makeEvent($this->ceremonyRequest('login-finish', body: ['ceremonyId' => 'cid']));
        $listener->onKernelRequest($event);

        /* the 418/429 choice mirrors LoginListener: `teapot` swaps the status but
         * not the meaning, and the point here is that the budget is shared */
        self::assertContains(
            $event->getResponse()?->getStatusCode(),
            [Response::HTTP_TOO_MANY_REQUESTS, Response::HTTP_I_AM_A_TEAPOT],
        );
    }

    /* ── success ──────────────────────────────────────────────────────── */

    public function test_a_successful_login_issues_a_session(): void
    {
        $credential = $this->credential('lyra');

        $passkeys = $this->createStub(PasskeyInterface::class);
        $passkeys->method('finishLogin')->willReturn($credential);

        $issuer = $this->createMock(SessionIssuerInterface::class);
        $issuer->expects(self::once())
            ->method('issue')
            ->with('lyra', Scope::Cookie, self::anything(), true)
            ->willReturn(new Response('', Response::HTTP_SEE_OTHER));

        $listener = $this->makeListener($passkeys, sessionIssuer: $issuer);
        $event = $this->makeEvent($this->ceremonyRequest('login-finish', body: ['ceremonyId' => 'cid']));

        $listener->onKernelRequest($event);

        self::assertSame(Response::HTTP_SEE_OTHER, $event->getResponse()?->getStatusCode());
    }

    public function test_a_successful_registration_issues_a_session(): void
    {
        $credential = $this->credential('lyra');

        $passkeys = $this->createStub(PasskeyInterface::class);
        $passkeys->method('finishRegistration')->willReturn($credential);

        $issuer = $this->createMock(SessionIssuerInterface::class);
        $issuer->expects(self::once())->method('issue')->willReturn(new Response('', Response::HTTP_SEE_OTHER));

        $listener = $this->makeListener($passkeys, sessionIssuer: $issuer);
        $event = $this->makeEvent($this->ceremonyRequest('register-finish', body: ['ceremonyId' => 'cid']));

        $listener->onKernelRequest($event);

        self::assertSame(Response::HTTP_SEE_OTHER, $event->getResponse()?->getStatusCode());
    }

    /* ── the resource guard ───────────────────────────────────────────── */

    /**
     * `begin` is bounded so an unauthenticated caller cannot fill the ceremony
     * cache — but this guard is deliberately separate from the login budget.
     */
    public function test_begin_is_rate_limited_as_a_resource_guard(): void
    {
        $passkeys = $this->createStub(PasskeyInterface::class);
        $passkeys->method('beginLogin')->willReturn(['publicKey' => [], 'ceremonyId' => 'cid']);

        $listener = $this->makeListener($passkeys, beginLimit: 2);

        for ($i = 0; $i < 2; ++$i) {
            $listener->onKernelRequest($this->makeEvent($this->ceremonyRequest('login-begin')));
        }

        $event = $this->makeEvent($this->ceremonyRequest('login-begin'));
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        self::assertTrue($response->headers->has('Retry-After'));
    }

    /**
     * A successful `begin` must not spend failure budget: otherwise simply
     * opening the login page would count against the user, and ten legitimately
     * started ceremonies would lock them out.
     */
    public function test_begin_does_not_consume_the_login_budget(): void
    {
        $passkeys = $this->createStub(PasskeyInterface::class);
        $passkeys->method('beginLogin')->willReturn(['publicKey' => [], 'ceremonyId' => 'cid']);
        $passkeys->method('finishLogin')->willReturn(null);

        $listener = $this->makeListener($passkeys);

        /* 10 begins, well inside the burst guard, then a failed login must still
         * be answered as a normal 401 rather than an exhausted-budget response */
        for ($i = 0; $i < 10; ++$i) {
            $listener->onKernelRequest($this->makeEvent($this->ceremonyRequest('login-begin')));
        }

        $event = $this->makeEvent($this->ceremonyRequest('login-finish', body: ['ceremonyId' => 'cid']));
        $listener->onKernelRequest($event);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $event->getResponse()?->getStatusCode());
    }

    /**
     * An empty body is not an error in itself: the ceremony id is simply
     * missing, so the request is refused the same way a malformed one is.
     * Asserted separately because it is the shape a bare `fetch()` produces.
     */
    public function test_an_empty_body_is_refused(): void
    {
        $passkeys = $this->createStub(PasskeyInterface::class);
        $passkeys->method('finishLogin')->willReturn(null);

        $listener = $this->makeListener($passkeys);
        $event = $this->makeEvent($this->ceremonyRequest('login-finish'));

        $listener->onKernelRequest($event);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $event->getResponse()?->getStatusCode());
    }
}
