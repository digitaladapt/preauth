<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\PasskeyConfigurationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Single source of truth for whether passkeys may be offered, and under which
 * relying-party identity.
 *
 * Two project decisions are enforced here and nowhere else:
 *
 *  - **D1 — central auth is a prerequisite.** A passkey is only meaningful when
 *    the whole base domain shares one relying party, so passkeys are unavailable
 *    unless `SUBDOMAIN_REDIRECT` is on and `AUTH_SUBDOMAIN` resolves to a base
 *    domain. The RP ID is therefore always that base domain.
 *
 *  - **D4 — HTTPS is required, with no exemption.** The origin handed to the
 *    browser is built here as `https://{authSubdomain}` and is *never* derived
 *    from the incoming request, so an `http://` origin can never be accepted.
 *    The deprecated `setSecuredRelyingPartyId()` escape hatch is not used, and
 *    there is deliberately no configuration override that could reintroduce one.
 */
interface PasskeyPolicyInterface
{
    /**
     * True when passkeys are switched on by configuration AND that configuration
     * satisfies D1. Availability to a particular visitor additionally requires
     * {@see isAvailableFor()}.
     */
    public function isEnabled(): bool;

    /**
     * Whether the passkey UI should be offered for this request.
     *
     * Requires the feature to be enabled, the request to be aimed at the auth
     * subdomain (the only place a ceremony may run), and the visitor to actually
     * be on HTTPS — see the note on scheme handling in {@see PasskeyPolicy}.
     */
    public function isAvailableFor(Request $request): bool;

    /**
     * The relying party ID: always the base domain of the auth subdomain.
     *
     * @throws PasskeyConfigurationException when passkeys are enabled without D1
     */
    public function rpId(): string;

    /**
     * Relying party origins allowed to complete a ceremony.
     *
     * Always exactly one entry, always `https://`, always derived from
     * configuration rather than from the request (D4).
     *
     * @return string[]
     *
     * @throws PasskeyConfigurationException when passkeys are enabled without D1
     */
    public function allowedOrigins(): array;

    /** The auth subdomain that ceremonies must be served from. */
    public function authSubdomain(): string;

    /** Human-readable name shown in the authenticator prompt. */
    public function rpName(): string;

    /** `required`, `preferred` or `discouraged`. */
    public function userVerification(): string;

    /** Ceremony timeout in milliseconds, as passed to the browser. */
    public function timeout(): int;

    /**
     * Fails hard when passkeys are enabled in a configuration that cannot work.
     *
     * Called during cache warm-up so a misconfigured deployment never reaches a
     * browser: the container refuses to start instead.
     *
     * @throws PasskeyConfigurationException
     */
    public function assertConfigurationIsUsable(): void;
}
