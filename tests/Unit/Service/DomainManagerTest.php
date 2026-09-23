<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\DomainManager;
use PHPUnit\Framework\TestCase;

final class DomainManagerTest extends TestCase
{
    private function createManager(bool $subdomainRedirect, string $authSubdomain): DomainManager
    {
        return new DomainManager($subdomainRedirect, $authSubdomain);
    }

    /* ── authBase / getAuthSubdomain ─────────────────────────────────────── */

    public function test_auth_base_is_null_when_subdomain_redirect_is_disabled(): void
    {
        $manager = $this->createManager(false, 'auth.example.com');
        self::assertNull($manager->authBase());
        self::assertNull($manager->getAuthSubdomain());
    }

    public function test_auth_base_is_null_when_auth_subdomain_is_empty(): void
    {
        $manager = $this->createManager(true, '');
        self::assertNull($manager->authBase());
        self::assertNull($manager->getAuthSubdomain());
    }

    public function test_auth_base_extracts_simple_domain(): void
    {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertSame('example.com', $manager->authBase());
        self::assertSame('auth.example.com', $manager->getAuthSubdomain());
    }

    public function test_auth_base_extracts_multi_part_tld(): void
    {
        $manager = $this->createManager(true, 'auth.example.co.uk');
        self::assertSame('example.co.uk', $manager->authBase());
        self::assertSame('auth.example.co.uk', $manager->getAuthSubdomain());
    }

    public function test_auth_base_is_null_for_localhost_auth(): void
    {
        $manager = $this->createManager(true, 'localhost');
        self::assertNull($manager->authBase());
        self::assertNull($manager->getAuthSubdomain());
    }

    public function test_auth_base_is_null_for_ip_auth(): void
    {
        $manager = $this->createManager(true, '192.168.1.1');
        self::assertNull($manager->authBase());
        self::assertNull($manager->getAuthSubdomain());
    }

    /* ── validReturn ──────────────────────────────────────────────────────── */

    public function test_valid_return_accepts_any_url_when_no_subdomain(): void
    {
        $manager = $this->createManager(false, '');
        self::assertTrue($manager->validReturn('https://evil.com/page'));
        self::assertTrue($manager->validReturn('https://example.com/ok'));
    }

    public function test_valid_return_rejects_invalid_url(): void
    {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertFalse($manager->validReturn('not-a-url'));
        self::assertFalse($manager->validReturn(''));
    }

    public function test_valid_return_accepts_same_base_domain(): void
    {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertTrue($manager->validReturn('https://app.example.com/dashboard'));
        self::assertTrue($manager->validReturn('https://example.com/'));
    }

    public function test_valid_return_rejects_different_base_domain(): void
    {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertFalse($manager->validReturn('https://evil.com/phish'));
        self::assertFalse($manager->validReturn('https://other-example.com/'));
    }

    public function test_valid_return_handles_co_uk_tld(): void
    {
        $manager = $this->createManager(true, 'auth.example.co.uk');
        self::assertTrue($manager->validReturn('https://www.example.co.uk/'));
        self::assertFalse($manager->validReturn('https://example.com/'));
    }

    public function test_valid_return_rejects_url_without_host(): void
    {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertFalse($manager->validReturn('mailto:test@example.com'));
    }

    /* ── matchesAuth ──────────────────────────────────────────────────────── */

    public function test_matches_auth_is_false_when_subdomain_redirect_disabled(): void
    {
        $manager = $this->createManager(false, 'auth.example.com');
        self::assertFalse($manager->matchesAuth('example.com'));
        self::assertFalse($manager->matchesAuth('app.example.com'));
    }

    public function test_matches_auth_is_false_when_auth_subdomain_is_empty(): void
    {
        $manager = $this->createManager(true, '');
        self::assertFalse($manager->matchesAuth('example.com'));
    }

    public function test_matches_auth_matches_same_base_domain(): void
    {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertTrue($manager->matchesAuth('example.com'));
        self::assertTrue($manager->matchesAuth('app.example.com'));
    }

    public function test_matches_auth_rejects_different_base_domain(): void
    {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertFalse($manager->matchesAuth('evil.com'));
        self::assertFalse($manager->matchesAuth('example.org'));
    }

    public function test_matches_auth_handles_multi_part_tld(): void
    {
        $manager = $this->createManager(true, 'auth.example.co.uk');
        self::assertTrue($manager->matchesAuth('www.example.co.uk'));
        self::assertFalse($manager->matchesAuth('example.com'));
    }

    public function test_matches_auth_rejects_ip_host(): void
    {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertFalse($manager->matchesAuth('192.168.1.1'));
    }

    public function test_matches_auth_rejects_localhost(): void
    {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertFalse($manager->matchesAuth('localhost'));
    }

    /* ── baseDomain edge cases via matchesAuth ────────────────────────────── */

    public function test_matches_auth_with_deep_subdomain(): void
    {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertTrue($manager->matchesAuth('a.b.c.example.com'));
    }

    public function test_matches_auth_with_two_part_domain(): void
    {
        /* for a 2-part auth subdomain, the baseDomain retains both parts */
        $manager = $this->createManager(true, 'auth.local');
        self::assertSame('auth.local', $manager->authBase());
        self::assertTrue($manager->matchesAuth('auth.local'));
        self::assertFalse($manager->matchesAuth('local'));
        self::assertFalse($manager->matchesAuth('app.local'));
    }

    /* ── TLD table coverage ──────────────────────────────────────────────── */

    public function test_matches_auth_with_com_au_tld(): void
    {
        // com.au IS in the TLD table (au => [com,...], so *.com.au IS multi-part
        $manager = $this->createManager(true, 'auth.example.com.au');
        self::assertSame('example.com.au', $manager->authBase());
        self::assertTrue($manager->matchesAuth('app.example.com.au'));
        self::assertFalse($manager->matchesAuth('example.com'));
    }

    public function test_matches_auth_with_co_jp_tld(): void
    {
        // co.jp IS in the TLD table (jp => [co,...], so *.co.jp IS multi-part
        $manager = $this->createManager(true, 'auth.example.co.jp');
        self::assertSame('example.co.jp', $manager->authBase());
        self::assertTrue($manager->matchesAuth('www.example.co.jp'));
    }

    public function test_matches_auth_with_com_br_tld(): void
    {
        // com.br: TLD table has br => [com,...], so *.com.br IS multi-part
        $manager = $this->createManager(true, 'auth.example.com.br');
        self::assertSame('example.com.br', $manager->authBase());
        self::assertTrue($manager->matchesAuth('app.example.com.br'));
    }

    public function test_matches_auth_with_co_nz_tld(): void
    {
        // co.nz is NOT in the TLD table (nz => [co,net,org], so *.co.nz IS multi-part)
        $manager = $this->createManager(true, 'auth.example.co.nz');
        self::assertSame('example.co.nz', $manager->authBase());
        self::assertTrue($manager->matchesAuth('sub.example.co.nz'));
    }

    public function test_matches_auth_with_com_mx_tld(): void
    {
        // com.mx is NOT in the TLD table (mx => [com,net,org], so *.com.mx IS multi-part)
        $manager = $this->createManager(true, 'auth.example.com.mx');
        self::assertSame('example.com.mx', $manager->authBase());
        self::assertTrue($manager->matchesAuth('app.example.com.mx'));
    }

    public function test_matches_auth_with_co_in_tld(): void
    {
        // co.in: in => [co,...], so *.co.in IS multi-part
        $manager = $this->createManager(true, 'auth.example.co.in');
        self::assertSame('example.co.in', $manager->authBase());
        self::assertTrue($manager->matchesAuth('app.example.co.in'));
    }

    public function test_matches_auth_with_br_com_tld(): void
    {
        // br.com: TLD table has com => [br], so *.br.com IS multi-part
        $manager = $this->createManager(true, 'auth.example.br.com');
        self::assertSame('example.br.com', $manager->authBase());
        self::assertTrue($manager->matchesAuth('app.example.br.com'));
    }

    public function test_simple_tld_not_treated_as_multi_part(): void
    {
        // example.com is a standard 2-part domain, not multi-part
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertSame('example.com', $manager->authBase());
        // auth.example.org should NOT match example.com
        self::assertFalse($manager->matchesAuth('app.example.org'));
    }

    /* ── baseDomain edge cases ───────────────────────────────────────────── */

    public function test_matches_auth_with_single_label_host(): void
    {
        // a single-label domain (not localhost, not IP) has baseLength 1
        // so 'myhost' has baseDomain 'myhost', while 'auth.local' has base 'auth.local'
        // they won't match unless the auth subdomain itself is single-label
        $manager = $this->createManager(true, 'auth.local');
        // auth.local base is 'auth.local', 'local' base is 'local' -> no match
        self::assertFalse($manager->matchesAuth('local'));
        // but a subdomain of auth.local does match
        self::assertTrue($manager->matchesAuth('app.auth.local'));
    }

    public function test_matches_auth_with_empty_string_host(): void
    {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertFalse($manager->matchesAuth(''));
    }

    public function test_valid_return_accepts_url_with_port(): void
    {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertTrue($manager->validReturn('https://example.com:8080/path'));
    }

    public function test_valid_return_accepts_url_without_path(): void
    {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertTrue($manager->validReturn('https://example.com'));
    }

    public function test_valid_return_rejects_different_domain_with_port(): void
    {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertFalse($manager->validReturn('https://evil.com:8080/path'));
    }
}
