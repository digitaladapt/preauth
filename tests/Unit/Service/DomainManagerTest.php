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

    public function testAuthBaseIsNullWhenSubdomainRedirectIsDisabled(): void
    {
        $manager = $this->createManager(false, 'auth.example.com');
        self::assertNull($manager->authBase());
        self::assertNull($manager->getAuthSubdomain());
    }

    public function testAuthBaseIsNullWhenAuthSubdomainIsEmpty(): void
    {
        $manager = $this->createManager(true, '');
        self::assertNull($manager->authBase());
        self::assertNull($manager->getAuthSubdomain());
    }

    public function testAuthBaseExtractsSimpleDomain(): void
    {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertSame('example.com', $manager->authBase());
        self::assertSame('auth.example.com', $manager->getAuthSubdomain());
    }

    public function testAuthBaseExtractsMultiPartTld(): void
    {
        $manager = $this->createManager(true, 'auth.example.co.uk');
        self::assertSame('example.co.uk', $manager->authBase());
        self::assertSame('auth.example.co.uk', $manager->getAuthSubdomain());
    }

    public function testAuthBaseIsNullForLocalhostAuth(): void
    {
        $manager = $this->createManager(true, 'localhost');
        self::assertNull($manager->authBase());
        self::assertNull($manager->getAuthSubdomain());
    }

    public function testAuthBaseIsNullForIpAuth(): void
    {
        $manager = $this->createManager(true, '192.168.1.1');
        self::assertNull($manager->authBase());
        self::assertNull($manager->getAuthSubdomain());
    }

    /* ── validReturn ──────────────────────────────────────────────────────── */

    public function testValidReturnAcceptsAnyUrlWhenNoSubdomain(): void
    {
        $manager = $this->createManager(false, '');
        self::assertTrue($manager->validReturn('https://evil.com/page'));
        self::assertTrue($manager->validReturn('https://example.com/ok'));
    }

    public function testValidReturnRejectsInvalidUrl(): void
    {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertFalse($manager->validReturn('not-a-url'));
        self::assertFalse($manager->validReturn(''));
    }

    public function testValidReturnAcceptsSameBaseDomain(): void
    {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertTrue($manager->validReturn('https://app.example.com/dashboard'));
        self::assertTrue($manager->validReturn('https://example.com/'));
    }

    public function testValidReturnRejectsDifferentBaseDomain(): void
    {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertFalse($manager->validReturn('https://evil.com/phish'));
        self::assertFalse($manager->validReturn('https://other-example.com/'));
    }

    public function testValidReturnHandlesCoUkTld(): void
    {
        $manager = $this->createManager(true, 'auth.example.co.uk');
        self::assertTrue($manager->validReturn('https://www.example.co.uk/'));
        self::assertFalse($manager->validReturn('https://example.com/'));
    }

    public function testValidReturnRejectsUrlWithoutHost(): void
    {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertFalse($manager->validReturn('mailto:test@example.com'));
    }

    /* ── matchesAuth ──────────────────────────────────────────────────────── */

    public function testMatchesAuthIsFalseWhenSubdomainRedirectDisabled(): void
    {
        $manager = $this->createManager(false, 'auth.example.com');
        self::assertFalse($manager->matchesAuth('example.com'));
        self::assertFalse($manager->matchesAuth('app.example.com'));
    }

    public function testMatchesAuthIsFalseWhenAuthSubdomainIsEmpty(): void
    {
        $manager = $this->createManager(true, '');
        self::assertFalse($manager->matchesAuth('example.com'));
    }

    public function testMatchesAuthMatchesSameBaseDomain(): void
    {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertTrue($manager->matchesAuth('example.com'));
        self::assertTrue($manager->matchesAuth('app.example.com'));
    }

    public function testMatchesAuthRejectsDifferentBaseDomain(): void
    {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertFalse($manager->matchesAuth('evil.com'));
        self::assertFalse($manager->matchesAuth('example.org'));
    }

    public function testMatchesAuthHandlesMultiPartTld(): void
    {
        $manager = $this->createManager(true, 'auth.example.co.uk');
        self::assertTrue($manager->matchesAuth('www.example.co.uk'));
        self::assertFalse($manager->matchesAuth('example.com'));
    }

    public function testMatchesAuthRejectsIpHost(): void
    {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertFalse($manager->matchesAuth('192.168.1.1'));
    }

    public function testMatchesAuthRejectsLocalhost(): void
    {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertFalse($manager->matchesAuth('localhost'));
    }

    /* ── baseDomain edge cases via matchesAuth ────────────────────────────── */

    public function testMatchesAuthWithDeepSubdomain(): void
    {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertTrue($manager->matchesAuth('a.b.c.example.com'));
    }

    public function testMatchesAuthWithTwoPartDomain(): void
    {
        /* for a 2-part auth subdomain, the baseDomain retains both parts */
        $manager = $this->createManager(true, 'auth.local');
        self::assertSame('auth.local', $manager->authBase());
        self::assertTrue($manager->matchesAuth('auth.local'));
        self::assertFalse($manager->matchesAuth('local'));
        self::assertFalse($manager->matchesAuth('app.local'));
    }

    /* ── TLD table coverage ──────────────────────────────────────────────── */

    public function testMatchesAuthWithComAuTld(): void
    {
        // com.au is NOT in the TLD table (table has au? no, it doesn't),
        // so it's treated as a standard 2-part TLD: base = com.au
        $manager = $this->createManager(true, 'auth.example.com.au');
        self::assertSame('com.au', $manager->authBase());
        self::assertTrue($manager->matchesAuth('app.example.com.au'));
        self::assertFalse($manager->matchesAuth('example.com'));
    }

    public function testMatchesAuthWithCoJpTld(): void
    {
        // co.jp is NOT in the TLD table (table has jpn under com, not jp under co)
        // so base = co.jp
        $manager = $this->createManager(true, 'auth.example.co.jp');
        self::assertSame('co.jp', $manager->authBase());
        self::assertTrue($manager->matchesAuth('www.example.co.jp'));
    }

    public function testMatchesAuthWithComBrTld(): void
    {
        // com.br: TLD table has com => [br], meaning *.br.com is multi-part
        // but com.br has last=br, TLD['br'] doesn't exist, so base = com.br
        $manager = $this->createManager(true, 'auth.example.com.br');
        self::assertSame('com.br', $manager->authBase());
        self::assertTrue($manager->matchesAuth('app.example.com.br'));
    }

    public function testMatchesAuthWithCoNzTld(): void
    {
        // co.nz is NOT in the TLD table (nz => [co,net,org], so *.co.nz IS multi-part)
        $manager = $this->createManager(true, 'auth.example.co.nz');
        self::assertSame('example.co.nz', $manager->authBase());
        self::assertTrue($manager->matchesAuth('sub.example.co.nz'));
    }

    public function testMatchesAuthWithComMxTld(): void
    {
        // com.mx is NOT in the TLD table (mx => [com,net,org], so *.com.mx IS multi-part)
        $manager = $this->createManager(true, 'auth.example.com.mx');
        self::assertSame('example.com.mx', $manager->authBase());
        self::assertTrue($manager->matchesAuth('app.example.com.mx'));
    }

    public function testMatchesAuthWithCoInTld(): void
    {
        // co.in: in => [co,...], so *.co.in IS multi-part
        $manager = $this->createManager(true, 'auth.example.co.in');
        self::assertSame('example.co.in', $manager->authBase());
        self::assertTrue($manager->matchesAuth('app.example.co.in'));
    }

    public function testMatchesAuthWithBrComTld(): void
    {
        // br.com: TLD table has com => [br], so *.br.com IS multi-part
        $manager = $this->createManager(true, 'auth.example.br.com');
        self::assertSame('example.br.com', $manager->authBase());
        self::assertTrue($manager->matchesAuth('app.example.br.com'));
    }

    public function testSimpleTldNotTreatedAsMultiPart(): void
    {
        // example.com is a standard 2-part domain, not multi-part
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertSame('example.com', $manager->authBase());
        // auth.example.org should NOT match example.com
        self::assertFalse($manager->matchesAuth('app.example.org'));
    }

    /* ── baseDomain edge cases ───────────────────────────────────────────── */

    public function testMatchesAuthWithSingleLabelHost(): void
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

    public function testMatchesAuthWithEmptyStringHost(): void
    {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertFalse($manager->matchesAuth(''));
    }

    public function testValidReturnAcceptsUrlWithPort(): void
    {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertTrue($manager->validReturn('https://example.com:8080/path'));
    }

    public function testValidReturnAcceptsUrlWithoutPath(): void
    {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertTrue($manager->validReturn('https://example.com'));
    }

    public function testValidReturnRejectsDifferentDomainWithPort(): void
    {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertFalse($manager->validReturn('https://evil.com:8080/path'));
    }
}
