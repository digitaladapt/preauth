<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Enum\UserVerification;
use App\Exception\PasskeyConfigurationException;
use App\Service\DomainInterface;
use App\Service\PasskeyPolicy;
use App\Tests\Support\TotpTestHelper;
use Override;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Covers the availability rule (D1) and the HTTPS requirement (D4).
 *
 * The two decisions are enforced in one place precisely so that they can be
 * tested exhaustively here rather than re-derived at each call site.
 */
final class PasskeyPolicyTest extends TestCase
{
    use TotpTestHelper;

    /**
     * A stand-in for the real DomainManager that mirrors its base-domain rule:
     * `localhost` and bare IPs yield null; otherwise the last two labels are
     * kept, or three when the final two form a known multi-part TLD.
     *
     * Verified against DomainManager: "auth.example.com" => "example.com",
     * "auth.example.co.uk" => "example.co.uk", "auth" => "auth",
     * "localhost" => null.
     */
    private function makeDomain(bool $subdomainRedirect, string $authSubdomain): DomainInterface
    {
        $authBase = null;
        if ('' !== $authSubdomain
            && 'localhost' !== $authSubdomain
            && !filter_var($authSubdomain, \FILTER_VALIDATE_IP)
        ) {
            $parts = explode('.', strtolower($authSubdomain));
            $keep = 2;
            $count = \count($parts);
            if ($count > 2 && 'uk' === $parts[$count - 1] && \in_array($parts[$count - 2], ['co', 'org', 'ac', 'gov'], true)) {
                $keep = 3;
            }
            $authBase = implode('.', \array_slice($parts, -min($keep, $count)));
        }

        return new class($subdomainRedirect, $authSubdomain, $subdomainRedirect ? $authBase : null) implements DomainInterface {
            public function __construct(
                private bool $redirect,
                private string $authSubdomain,
                private ?string $authBase,
            ) {
            }

            #[Override]
            public function getAuthSubdomain(): ?string
            {
                return $this->redirect ? $this->authSubdomain : null;
            }

            #[Override]
            public function validReturn(string $url): bool
            {
                return $this->redirect;
            }

            #[Override]
            public function matchesAuth(string $host): bool
            {
                return $this->redirect;
            }

            #[Override]
            public function authBase(): ?string
            {
                return $this->authBase;
            }
        };
    }

    private function makePolicy(
        bool $passkeyEnabled = true,
        bool $subdomainRedirect = true,
        string $authSubdomain = 'auth.example.com',
        string $userVerification = 'required',
        int $timeout = 60000,
        string $rpName = '',
        string $title = 'Pre-Authentication System',
    ): PasskeyPolicy {
        $config = $this->makeConfig(
            passkeyEnabled: $passkeyEnabled,
            passkeyUserVerification: $userVerification,
            passkeyTimeout: $timeout,
            passkeyRpName: $rpName,
            title: $title,
        );

        return new PasskeyPolicy($config, $this->makeDomain($subdomainRedirect, $authSubdomain));
    }

    /* ── D1: enabled + prerequisite ─────────────────────────────────────── */

    public function test_enabled_requires_both_the_switch_and_central_auth(): void
    {
        self::assertTrue($this->makePolicy()->isEnabled());
        self::assertFalse($this->makePolicy(passkeyEnabled: false)->isEnabled());
        self::assertFalse($this->makePolicy(subdomainRedirect: false)->isEnabled());
    }

    public function test_rp_id_is_always_the_auth_base_domain(): void
    {
        self::assertSame('example.com', $this->makePolicy()->rpId());
        self::assertSame('example.co.uk', $this->makePolicy(authSubdomain: 'auth.example.co.uk')->rpId());
    }

    public function test_rp_id_throws_when_not_configured(): void
    {
        $this->expectException(PasskeyConfigurationException::class);
        $this->makePolicy(subdomainRedirect: false)->rpId();
    }

    /* ── D4: HTTPS is the only accepted origin ─────────────────────────── */

    public function test_allowed_origin_is_always_https(): void
    {
        self::assertSame(['https://auth.example.com'], $this->makePolicy()->allowedOrigins());
    }

    public function test_allowed_origin_never_reflects_the_request_scheme(): void
    {
        $policy = $this->makePolicy();
        $request = Request::create('http://auth.example.com/', 'GET');

        self::assertSame(['https://auth.example.com'], $policy->allowedOrigins());
        self::assertFalse($policy->isAvailableFor($request));
    }

    public function test_available_only_on_the_auth_host_over_https(): void
    {
        $policy = $this->makePolicy();

        $secure = Request::create('https://auth.example.com/', 'GET');
        $insecure = Request::create('http://auth.example.com/', 'GET');
        $otherHost = Request::create('https://app.example.com/', 'GET');

        self::assertTrue($policy->isAvailableFor($secure));
        self::assertFalse($policy->isAvailableFor($insecure));
        self::assertFalse($policy->isAvailableFor($otherHost));
    }

    public function test_available_respects_the_switch(): void
    {
        $request = Request::create('https://auth.example.com/', 'GET');

        self::assertFalse($this->makePolicy(passkeyEnabled: false)->isAvailableFor($request));
    }

    /* ── boot-time assertion (D1 + D4) ─────────────────────────────────── */

    public function test_assertion_is_silent_when_disabled(): void
    {
        $this->makePolicy(passkeyEnabled: false, subdomainRedirect: false)->assertConfigurationIsUsable();
        $this->addToAssertionCount(1);
    }

    public function test_assertion_passes_for_a_valid_configuration(): void
    {
        $this->makePolicy()->assertConfigurationIsUsable();
        $this->addToAssertionCount(1);
    }

    public function test_assertion_fails_without_central_auth(): void
    {
        $this->expectException(PasskeyConfigurationException::class);
        $this->expectExceptionMessageMatches('/central authentication is not configured/');
        $this->makePolicy(subdomainRedirect: false, authSubdomain: '')->assertConfigurationIsUsable();
    }

    public function test_assertion_fails_for_localhost(): void
    {
        /* localhost has no base domain, so it can never satisfy D1 */
        $this->expectException(PasskeyConfigurationException::class);
        $this->makePolicy(authSubdomain: 'localhost')->assertConfigurationIsUsable();
    }

    public function test_assertion_fails_for_a_single_label_subdomain(): void
    {
        /* D4: no certificate can be issued for a single-label host */
        $this->expectException(PasskeyConfigurationException::class);
        $this->expectExceptionMessageMatches('/fully qualified domain name/');
        $this->makePolicy(authSubdomain: 'auth')->assertConfigurationIsUsable();
    }

    /* ── configuration accessors ───────────────────────────────────────── */

    public function test_rp_name_falls_back_to_the_title(): void
    {
        self::assertSame('Pre-Authentication System', $this->makePolicy(rpName: '')->rpName());
        self::assertSame('My Gateway', $this->makePolicy(rpName: 'My Gateway')->rpName());
    }

    public function test_user_verification_and_timeout_are_passed_through(): void
    {
        $policy = $this->makePolicy(userVerification: 'preferred', timeout: 30000);

        self::assertSame('preferred', $policy->userVerification());
        self::assertSame(30000, $policy->timeout());
    }

    public function test_unknown_user_verification_falls_back_to_required(): void
    {
        /* an unrecognised value must never silently weaken the requirement */
        $policy = $this->makePolicy(
            userVerification: 'nonsense',
        );

        self::assertSame(UserVerification::Required->value, $policy->userVerification());
    }

    public function test_non_positive_timeout_falls_back_to_the_default(): void
    {
        self::assertSame(60000, $this->makePolicy(timeout: 0)->timeout());
        self::assertSame(60000, $this->makePolicy(timeout: -100)->timeout());
    }

    public function test_auth_subdomain_is_exposed_for_ceremony_urls(): void
    {
        self::assertSame('auth.example.com', $this->makePolicy()->authSubdomain());
    }
}
