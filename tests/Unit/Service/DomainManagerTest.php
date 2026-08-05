<?php
declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\DomainManager;
use PHPUnit\Framework\TestCase;

final class DomainManagerTest extends TestCase {
    private function createManager(bool $subdomainRedirect, string $authSubdomain): DomainManager {
        return new DomainManager($subdomainRedirect, $authSubdomain);
    }

    /* ── authBase / getAuthSubdomain ─────────────────────────────────────── */

    public function testAuthBaseIsNullWhenSubdomainRedirectIsDisabled(): void {
        $manager = $this->createManager(false, 'auth.example.com');
        self::assertNull($manager->authBase());
        self::assertNull($manager->getAuthSubdomain());
    }

    public function testAuthBaseIsNullWhenAuthSubdomainIsEmpty(): void {
        $manager = $this->createManager(true, '');
        self::assertNull($manager->authBase());
        self::assertNull($manager->getAuthSubdomain());
    }

    public function testAuthBaseExtractsSimpleDomain(): void {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertSame('example.com', $manager->authBase());
        self::assertSame('auth.example.com', $manager->getAuthSubdomain());
    }

    public function testAuthBaseExtractsMultiPartTld(): void {
        $manager = $this->createManager(true, 'auth.example.co.uk');
        self::assertSame('example.co.uk', $manager->authBase());
        self::assertSame('auth.example.co.uk', $manager->getAuthSubdomain());
    }

    public function testAuthBaseIsNullForLocalhostAuth(): void {
        $manager = $this->createManager(true, 'localhost');
        self::assertNull($manager->authBase());
        self::assertNull($manager->getAuthSubdomain());
    }

    public function testAuthBaseIsNullForIpAuth(): void {
        $manager = $this->createManager(true, '192.168.1.1');
        self::assertNull($manager->authBase());
        self::assertNull($manager->getAuthSubdomain());
    }

    /* ── validReturn ──────────────────────────────────────────────────────── */

    public function testValidReturnAcceptsAnyUrlWhenNoSubdomain(): void {
        $manager = $this->createManager(false, '');
        self::assertTrue($manager->validReturn('https://evil.com/page'));
        self::assertTrue($manager->validReturn('https://example.com/ok'));
    }

    public function testValidReturnRejectsInvalidUrl(): void {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertFalse($manager->validReturn('not-a-url'));
        self::assertFalse($manager->validReturn(''));
    }

    public function testValidReturnAcceptsSameBaseDomain(): void {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertTrue($manager->validReturn('https://app.example.com/dashboard'));
        self::assertTrue($manager->validReturn('https://example.com/'));
    }

    public function testValidReturnRejectsDifferentBaseDomain(): void {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertFalse($manager->validReturn('https://evil.com/phish'));
        self::assertFalse($manager->validReturn('https://other-example.com/'));
    }

    public function testValidReturnHandlesCoUkTld(): void {
        $manager = $this->createManager(true, 'auth.example.co.uk');
        self::assertTrue($manager->validReturn('https://www.example.co.uk/'));
        self::assertFalse($manager->validReturn('https://example.com/'));
    }

    public function testValidReturnRejectsUrlWithoutHost(): void {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertFalse($manager->validReturn('mailto:test@example.com'));
    }

    /* ── matchesAuth ──────────────────────────────────────────────────────── */

    public function testMatchesAuthIsFalseWhenSubdomainRedirectDisabled(): void {
        $manager = $this->createManager(false, 'auth.example.com');
        self::assertFalse($manager->matchesAuth('example.com'));
        self::assertFalse($manager->matchesAuth('app.example.com'));
    }

    public function testMatchesAuthIsFalseWhenAuthSubdomainIsEmpty(): void {
        $manager = $this->createManager(true, '');
        self::assertFalse($manager->matchesAuth('example.com'));
    }

    public function testMatchesAuthMatchesSameBaseDomain(): void {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertTrue($manager->matchesAuth('example.com'));
        self::assertTrue($manager->matchesAuth('app.example.com'));
    }

    public function testMatchesAuthRejectsDifferentBaseDomain(): void {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertFalse($manager->matchesAuth('evil.com'));
        self::assertFalse($manager->matchesAuth('example.org'));
    }

    public function testMatchesAuthHandlesMultiPartTld(): void {
        $manager = $this->createManager(true, 'auth.example.co.uk');
        self::assertTrue($manager->matchesAuth('www.example.co.uk'));
        self::assertFalse($manager->matchesAuth('example.com'));
    }

    public function testMatchesAuthRejectsIpHost(): void {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertFalse($manager->matchesAuth('192.168.1.1'));
    }

    public function testMatchesAuthRejectsLocalhost(): void {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertFalse($manager->matchesAuth('localhost'));
    }

    /* ── baseDomain edge cases via matchesAuth ────────────────────────────── */

    public function testMatchesAuthWithDeepSubdomain(): void {
        $manager = $this->createManager(true, 'auth.example.com');
        self::assertTrue($manager->matchesAuth('a.b.c.example.com'));
    }

    public function testMatchesAuthWithTwoPartDomain(): void {
        /* for a 2-part auth subdomain, the baseDomain retains both parts */
        $manager = $this->createManager(true, 'auth.local');
        self::assertSame('auth.local', $manager->authBase());
        self::assertTrue($manager->matchesAuth('auth.local'));
        self::assertFalse($manager->matchesAuth('local'));
        self::assertFalse($manager->matchesAuth('app.local'));
    }
}
