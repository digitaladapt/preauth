<?php

declare(strict_types=1);

namespace App\Service;

use App\ConfigBag;
use App\Exception\PasskeyConfigurationException;
use Override;
use Symfony\Component\HttpFoundation\Request;

/**
 * @see PasskeyPolicyInterface for the decisions this class enforces
 *
 * **On scheme handling (D4).** The container serves plain HTTP and sits behind a
 * TLS-terminating proxy, so the application never learns the public scheme from
 * its own configuration — only from `X-Forwarded-Proto`, which Symfony resolves
 * through `trusted_proxies` / `trusted_headers` in `framework.yaml`. The scheme
 * is therefore enforced two independent ways rather than asserted at boot:
 *
 *  1. The allowed origin is hardcoded to `https://` here and is never taken from
 *     the request, so the library's origin check rejects an `http://` ceremony
 *     no matter how the request arrived.
 *  2. {@see isAvailableFor()} additionally requires the request to be secure, so
 *     a visitor on a non-secure connection is never shown a passkey button that
 *     the browser would refuse to act on.
 */
final readonly class PasskeyPolicy implements PasskeyPolicyInterface
{
    private const string SCHEME = 'https';

    public function __construct(
        private ConfigBag $config,
        private DomainInterface $domainManager,
    ) {
    }

    #[Override]
    public function isEnabled(): bool
    {
        return $this->config->passkeyEnabled() && null !== $this->domainManager->authBase();
    }

    #[Override]
    public function isAvailableFor(Request $request): bool
    {
        return $this->isEnabled()
            && $request->isSecure()
            && $this->domainManager->getAuthSubdomain() === $request->getHost();
    }

    #[Override]
    public function rpId(): string
    {
        return $this->domainManager->authBase() ?? throw $this->notConfigured();
    }

    #[Override]
    public function allowedOrigins(): array
    {
        return [self::SCHEME.'://'.$this->authSubdomain()];
    }

    #[Override]
    public function authSubdomain(): string
    {
        $subdomain = $this->domainManager->getAuthSubdomain();

        return $subdomain ?? throw $this->notConfigured();
    }

    #[Override]
    public function rpName(): string
    {
        return $this->config->passkeyRpName() ?: $this->config->title();
    }

    #[Override]
    public function userVerification(): string
    {
        return $this->config->passkeyUserVerification();
    }

    #[Override]
    public function timeout(): int
    {
        return $this->config->passkeyTimeout();
    }

    /**
     * @throws PasskeyConfigurationException
     */
    #[Override]
    public function assertConfigurationIsUsable(): void
    {
        if (!$this->config->passkeyEnabled()) {
            return;
        }

        /* D1: without a base domain there is no RP ID and no shared credential */
        if (null === $this->domainManager->authBase()) {
            throw new PasskeyConfigurationException('PASSKEY_ENABLED is on, but central authentication is not configured. Passkeys require SUBDOMAIN_REDIRECT=1 together with a valid AUTH_SUBDOMAIN whose base domain can be determined (a domain such as "auth.example.com" — not "localhost" and not an IP address). Either configure central authentication or set PASSKEY_ENABLED=0.');
        }

        /* D4: the subdomain must be a real domain able to present a TLS certificate.
         * authBase() returning null already excludes localhost and bare IPs, so
         * this guards against an auth subdomain that is a single label. */
        if (!str_contains($this->authSubdomain(), '.')) {
            throw new PasskeyConfigurationException('AUTH_SUBDOMAIN must be a fully qualified domain name (for example "auth.example.com") because passkeys require HTTPS and a certificate cannot be issued for a single-label host.');
        }
    }

    private function notConfigured(): PasskeyConfigurationException
    {
        return new PasskeyConfigurationException(
            'Passkeys are enabled but central authentication is not configured, '
            .'so no relying party identity is available.',
        );
    }
}
