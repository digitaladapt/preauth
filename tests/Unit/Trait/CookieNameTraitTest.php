<?php

declare(strict_types=1);

namespace App\Tests\Unit\Trait;

use App\Trait\CookieNameTrait;
use PHPUnit\Framework\TestCase;

final class CookieNameTraitTest extends TestCase
{
    use CookieNameTrait;

    public function test_cookie_name(): void
    {
        self::assertSame('__Host-Http-Preauth', $this->cookieName());
    }

    public function test_auth_cookie_name(): void
    {
        self::assertSame('__Http-Domain-Preauth', $this->authCookieName());
    }

    public function test_header_name(): void
    {
        self::assertSame('X-Preauth', $this->headerName());
    }
}
