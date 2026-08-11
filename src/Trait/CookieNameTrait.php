<?php

declare(strict_types=1);

namespace App\Trait;

use App\Service\DomainInterface;

trait CookieNameTrait
{
    private const string COOKIE_NAME = '__Host-Http-Preauth';
    private const string AUTH_COOKIE_NAME = '__Http-Domain-Preauth';
    private const string HEADER_NAME = 'X-Preauth';

    final protected function cookieName(): string
    {
        return static::COOKIE_NAME;
    }

    final protected function authCookieName(): string
    {
        return static::AUTH_COOKIE_NAME;
    }

    final protected function headerName(): string
    {
        return static::HEADER_NAME;
    }

    /**
     * Returns the appropriate cookie name based on whether central auth is active.
     * Uses the __Host- prefix for single-domain mode (no Domain attribute),
     * and a non-prefixed name for central auth (Domain attribute required).
     */
    final protected function sessionCookieName(DomainInterface $domainManager): string
    {
        return $domainManager->authBase() ? $this->authCookieName() : $this->cookieName();
    }

    /**
     * Returns the cookie domain for central auth mode, or null for single-domain.
     * The domain is only set when the host matches the auth base domain.
     */
    final protected function sessionCookieDomain(DomainInterface $domainManager, string $host): ?string
    {
        return $domainManager->matchesAuth($host) ? $domainManager->authBase() : null;
    }
}
