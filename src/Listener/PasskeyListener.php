<?php

declare(strict_types=1);

namespace App\Listener;

use App\AppConstants;
use App\ConfigBag;
use App\Enum\Scope;
use App\Service\DomainInterface;
use App\Service\PasskeyInterface;
use App\Service\PasskeyPolicyInterface;
use App\Service\SessionIssuerInterface;
use App\Trait\HasLoggerTrait;
use App\Trait\MakeNonceTrait;
use App\Trait\StringTrait;
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
 * **Three operations, not four.** `register-begin` is deliberately *absent*: a
 * registration ceremony may only be started after a valid TOTP code, which is
 * presented to `LoginManager` as part of the form submission. `LoginManager`
 * therefore starts that ceremony and returns its options with the login
 * response. Exposing `register-begin` here would be a way to obtain a challenge
 * **without proving anything**, which is the vulnerability rather than the
 * feature — there is no session cookie to check at that point either, since the
 * whole flow is what *produces* the session.
 *
 * **Every** request carrying the dispatch header gets a response, including
 * malformed ones. Falling through would let `InterceptListener` render HTML to a
 * `fetch()` caller.
 */
final readonly class PasskeyListener
{
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

    public const string BEGIN_LOGIN = 'login-begin';

    public const string FINISH_LOGIN = 'login-finish';

    public const string FINISH_REGISTER = 'register-finish';

    private RateLimiterFactoryInterface $beginLimiter;

    private RateLimiterFactoryInterface $loginLimiter;

    public function __construct(
        #[Target('passkey_begin_burst')] RateLimiterFactoryInterface $beginLimiter,
        #[Target('login_limiter')] RateLimiterFactoryInterface $loginLimiter,
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

    /**
     * The ceremony was authorised by the TOTP-gated hand-off in `LoginManager`,
     * so the capability here is the single-use `ceremonyId` itself: it is
     * server-issued, stored against the identity that passed the check, and
     * consumed on use.
     */
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
        $response->headers->set(AppConstants::PASSKEY_CEREMONY_MARKER, '1');
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
