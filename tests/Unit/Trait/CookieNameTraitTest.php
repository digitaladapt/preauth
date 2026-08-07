<?php
declare(strict_types=1);

namespace App\Tests\Unit\Trait;

use App\Trait\CookieNameTrait;
use PHPUnit\Framework\TestCase;

final class CookieNameTraitTest extends TestCase {
    use CookieNameTrait;

    public function testCookieName(): void {
        self::assertSame('__Host-Http-Preauth', $this->cookieName());
    }

    public function testAuthCookieName(): void {
        self::assertSame('__Http-Domain-Preauth', $this->authCookieName());
    }

    public function testHeaderName(): void {
        self::assertSame('X-Preauth', $this->headerName());
    }
}
