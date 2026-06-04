<?php
declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\DomainManager;
use PHPUnit\Framework\TestCase;

final class DomainManagerTest extends TestCase {
    public function testGetAuthSubdomainWhenEnabled(): void {
        $dm = new DomainManager(true, 'auth.example.com');
        self::assertSame('auth.example.com', $dm->getAuthSubdomain());
    }

    public function testGetAuthSubdomainWhenDisabled(): void {
        $dm = new DomainManager(false, 'auth.example.com');
        self::assertNull($dm->getAuthSubdomain());
    }

    public function testGetAuthSubdomainWithEmptySubdomain(): void {
        $dm = new DomainManager(true, '');
        self::assertNull($dm->getAuthSubdomain());
    }

    public function testAuthBaseWithSubdomain(): void {
        $dm = new DomainManager(true, 'auth.example.com');
        self::assertSame('example.com', $dm->authBase());
    }

    public function testAuthBaseWithoutSubdomain(): void {
        $dm = new DomainManager(false, 'auth.example.com');
        self::assertNull($dm->authBase());
    }

    public function testAuthBaseWithEmptySubdomain(): void {
        $dm = new DomainManager(true, '');
        self::assertNull($dm->authBase());
    }

    public function testAuthBaseWithLocalhost(): void {
        $dm = new DomainManager(true, 'localhost');
        self::assertNull($dm->authBase());
    }

    public function testAuthBaseWithIp(): void {
        $dm = new DomainManager(true, '192.168.1.1');
        self::assertNull($dm->authBase());
    }

    public function testMatchesAuthWithSameBase(): void {
        $dm = new DomainManager(true, 'auth.example.com');
        self::assertTrue($dm->matchesAuth('www.example.com'));
        self::assertTrue($dm->matchesAuth('app.example.com'));
    }

    public function testMatchesAuthWithDifferentBase(): void {
        $dm = new DomainManager(true, 'auth.example.com');
        self::assertFalse($dm->matchesAuth('other.com'));
    }

    public function testMatchesAuthWithIp(): void {
        $dm = new DomainManager(true, 'auth.example.com');
        self::assertFalse($dm->matchesAuth('192.168.1.1'));
    }

    public function testMatchesAuthWithLocalhost(): void {
        $dm = new DomainManager(true, 'auth.example.com');
        self::assertFalse($dm->matchesAuth('localhost'));
    }

    public function testMatchesAuthWhenDisabled(): void {
        $dm = new DomainManager(false, 'auth.example.com');
        self::assertFalse($dm->matchesAuth('www.example.com'));
    }

    public function testMatchesAuthWithCoUk(): void {
        $dm = new DomainManager(true, 'auth.example.co.uk');
        self::assertTrue($dm->matchesAuth('www.example.co.uk'));
        self::assertFalse($dm->matchesAuth('other.co.uk'));
    }

    public function testValidReturnWithValidUrl(): void {
        $dm = new DomainManager(false, '');
        self::assertTrue($dm->validReturn('https://example.com/path'));
    }

    public function testValidReturnWithInvalidUrl(): void {
        $dm = new DomainManager(false, '');
        self::assertFalse($dm->validReturn('not-a-url'));
    }

    public function testValidReturnWithEmptyString(): void {
        $dm = new DomainManager(false, '');
        self::assertFalse($dm->validReturn(''));
    }

    public function testValidReturnWithAuthSubdomainMatching(): void {
        $dm = new DomainManager(true, 'auth.example.com');
        self::assertTrue($dm->validReturn('https://www.example.com/path'));
    }

    public function testValidReturnWithAuthSubdomainNotMatching(): void {
        $dm = new DomainManager(true, 'auth.example.com');
        self::assertFalse($dm->validReturn('https://evil.com/path'));
    }

    public function testValidReturnWithNoHost(): void {
        $dm = new DomainManager(true, 'auth.example.com');
        self::assertFalse($dm->validReturn('javascript:void(0)'));
    }

    public function testMatchesAuthWithMultiPartTld(): void {
        $dm = new DomainManager(true, 'auth.example.com.br');
        self::assertTrue($dm->matchesAuth('www.example.com.br'));
    }

    public function testMatchesAuthWithUkCo(): void {
        $dm = new DomainManager(true, 'auth.example.co.uk');
        self::assertTrue($dm->matchesAuth('www.example.co.uk'));
    }

    public function testMatchesAuthWithOrgUk(): void {
        $dm = new DomainManager(true, 'auth.example.org.uk');
        self::assertTrue($dm->matchesAuth('www.example.org.uk'));
    }
}
