<?php

declare(strict_types=1);

namespace App\Listener;

use App\ConfigBag;
use App\Enum\Scope;
use App\Service\DomainInterface;
use App\Service\PasskeyInterface;
use App\Service\PasskeyPolicyInterface;
use App\Service\SessionIssuerInterface;
use App\Trait\CookieNameTrait;
use App\Trait\HasLoggerTrait;
use App\Trait\MakeNonceTrait;
use App\Trait\StringTrait;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Serves the passkey ceremonies.
 *
 * **Priority 70 — the whole design turns on this number.**
 *
 * - *After* `RejectListener` (77), so a rate-limited IP never reaches this code.
 *   Passkeys cannot be used to sidestep a lockout; that is decision D3.
 * - *Before* `LoginListener` (66), which is essential rather than tidy:
 *   `LoginListener` treats **any** POST to the auth subdomain as a login attempt,
 *   and a ceremony `finish` body has no `username`/`totp`, so `Payload::load()`
 *   returns null and the request would be scored as a failed login — burning a
 *   rate-limit token for every legitimate passkey login.
 *
 * **Every** request carrying the dispatch header gets a response, including
 * malformed ones. Falling through would let `InterceptListener` render HTML to a
 * `fetch()` caller.
 */
final readonly class PasskeyListener
{
    use CookieNameTrait;
    use HasLoggerTrait;
    use MakeNonceTrait;
    use StringTrait;

    /**
     * Marks a request as a ceremony call, and its value selects the operation.
     *
     * A distinct header rather than overloading `X-Preauth`: that one carries a
     * base64url `Payload` and is parsed as such.
     */
    public const string HEADER = 'X-Preauth-Passkey';

    /** Marks a response as ceremony output, so the caching policy can see it. */
    public const string CEREMONY_MARKER = 'X-Preauth-Ceremony';

    private const string BEGIN_LOGIN = 'login-begin';

    private const string FINISH_LOGIN = 'login-finish';

    private const string BEGIN_REGISTER = 'register-begin';

    private const string FINISH_REGISTER = 'register-finish';

    private RateLimiterFactoryInterface $beginLimiter;

    private RateLimiterFactoryInterface $loginLimiter;

    public function __construct(
        #[Target('passkey_begin_burst')] RateLimiterFactoryInterface $beginLimiter,
        #[Target('login_limiter')] RateLimiterFactoryInterface $loginLimiter,
        #[Target('sessionCache')] private CacheItemPoolInterface $sessionCache,
        private PasskeyInterface $passkeys,
        private PasskeyPolicyInterface $policy,
        private SessionIssuerInterface $sessionIssuer,
        private DomainInterface $domainManager,
        private ConfigBag $config,
    ) {
        $this->beginLimiter = $beginLimiter;
        $this->loginLimiter = $loginLimiter;
    }

    /**
     * @throws InvalidArgumentException
     */
    #[AsEventListener(priority: 70)]
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $operation = $request->headers->get(self::HEADER);

        if (null === $operation) {
            return;
        }

        /* Ceremonies exist only on the auth subdomain. Elsewhere the header is
         * ignored entirely, so this listener cannot be used to probe other hosts. */
        if ($this->domainManager->getAuthSubdomain() !== $request->getHost()) {
            return;
        }

        $event->setResponse($this->dispatch($operation, $request));
    }

    private function dispatch(string $operation, Request $request): Response
    {
        /* not available => behave as if the feature does not exist */
        if (!$this->policy->isAvailableFor($request)) {
            return $this->ceremonyResponse($this->error('Passkeys are not available.', $request));
        }

        return $this->ceremonyResponse(match ($operation) {
            self::BEGIN_LOGIN => $this->beginLogin($request),
            self::FINISH_LOGIN => $this->finishLogin($request),
            self::BEGIN_REGISTER => $this->beginRegistration($request),
            self::FINISH_REGISTER => $this->finishRegistration($request),
            default => $this->error('Unknown passkey operation.', $request),
        });
    }

    private function beginLogin(Request $request): Response
    {
        if ($limit = $this->beginBurstExceeded($request)) {
            return $limit;
        }

        return $this->json($this->passkeys->beginLogin());
    }

    /**
     * Registration is only offered to someone who already authenticated: the
     * identity comes from the live session, never from the request body, so a
     * caller cannot register a passkey for an identity it does not hold.
     */
    private function beginRegistration(Request $request): Response
    {
        $identity = $this->identityFromRequest($request);
        if (null === $identity) {
            return $this->error('Registration requires a completed login.', $request);
        }

        if ($limit = $this->beginBurstExceeded($request)) {
            return $limit;
        }

        return $this->json($this->passkeys->beginRegistration($identity));
    }

    private function finishLogin(Request $request): Response
    {
        $credential = $this->passkeys->finishLogin($this->body($request));

        if (null === $credential) {
            /* A failed ceremony consumes the same budget as a wrong TOTP code
             * (D3), so passkey guesses cannot outpace code guesses. */
            return $this->failure($request);
        }

        $this->logger->debug("passkey login succeeded for: {$credential->identity}");

        return $this->sessionIssuer->issue($credential->identity, Scope::Cookie, $request, true);
    }

    private function finishRegistration(Request $request): Response
    {
        $credential = $this->passkeys->finishRegistration($this->body($request));

        if (null === $credential) {
            return $this->failure($request);
        }

        $this->logger->debug("passkey registered for: {$credential->identity}");

        return $this->sessionIssuer->issue($credential->identity, Scope::Cookie, $request, true);
    }

    /**
     * The identity of an already-authenticated caller, for registration.
     *
     * Read from the live session cookie, so `register-begin` is reachable only by
     * someone who has just passed the TOTP check. Null when there is no session.
     *
     * @throws InvalidArgumentException
     */
    private function identityFromRequest(Request $request): ?string
    {
        $cookie = $request->cookies->get($this->sessionCookieName($this->domainManager));
        if (!\is_string($cookie) || '' === $cookie) {
            return null;
        }

        $item = $this->sessionCache->getItem($this->makeCacheKey("cookie_$cookie"));
        if (!$item->isHit()) {
            return null;
        }

        $identity = $item->get();

        return \is_string($identity) && '' !== $identity ? $identity : null;
    }

    /**
     * Bounds how many ceremonies one caller can start.
     *
     * A resource guard, not the login budget: a legitimate `begin` must not spend
     * failure budget, but an unbounded `begin` could fill the ceremony cache.
     */
    private function beginBurstExceeded(Request $request): ?Response
    {
        $limit = $this->beginLimiter->create((string) $request->getClientIp())->consume(1);
        if ($limit->isAccepted()) {
            return null;
        }

        $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
        $this->logger->debug("passkey begin rate-limited: {$request->getClientIp()}");

        $response = $this->error('Too many passkey attempts, please slow down.', $request);
        $response->setStatusCode(Response::HTTP_TOO_MANY_REQUESTS);
        $response->headers->set('Retry-After', (string) $retryAfter);

        return $response;
    }

    /**
     * A failed ceremony is indistinguishable from a wrong TOTP code, and spends
     * the same shared budget — including the same 418/429 outcome when exhausted.
     */
    private function failure(Request $request): Response
    {
        $limited = $this->loginLimiter
            ->create((string) $request->getClientIp())
            ->consume(1)
            ->getRemainingTokens() < 1;

        $this->logger->debug("passkey ceremony failed for: {$request->getClientIp()}");

        if ($limited) {
            $response = $this->error(
                $this->config->teapot() ? $this->config->teapotTitle() : $this->config->tooManyTitle(),
                $request,
            );
            $response->setStatusCode(
                $this->config->teapot() ? Response::HTTP_I_AM_A_TEAPOT : Response::HTTP_TOO_MANY_REQUESTS,
            );

            return $response;
        }

        $response = $this->error($this->config->errorMessage(), $request);
        $response->setStatusCode(Response::HTTP_UNAUTHORIZED);

        return $response;
    }

    /**
     * Mark a reply as ceremony output so the no-store policy can find it.
     *
     * These are the only browser-facing 2xx responses this application produces,
     * and `SecurityHeadersListener` otherwise assumes any 2xx is consumed by
     * `forward_auth` and leaves it cacheable.
     */
    private function ceremonyResponse(Response $response): Response
    {
        $response->headers->set(self::CEREMONY_MARKER, '1');
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * A JSON error carrying a fresh nonce and the same shape the login page
     * expects, so the caller can fall back to the TOTP form without a reload.
     *
     * @throws InvalidArgumentException
     */
    private function error(string $message, Request $request): Response
    {
        return new Response(
            (string) json_encode([
                'message' => $message,
                'nonce' => $this->makeNonce(),
                'post' => $this->domainManager->getAuthSubdomain() === $request->getHost(),
                'username' => '',
            ]),
            Response::HTTP_UNAUTHORIZED,
        );
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function json(array $payload): Response
    {
        return new Response((string) json_encode($payload), Response::HTTP_OK);
    }

    /**
     * @return array<string,mixed>
     */
    private function body(Request $request): array
    {
        $raw = $request->getContent();
        if ('' === $raw) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
