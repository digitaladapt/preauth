<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\Scope;
use PHPUnit\Framework\TestCase;

final class ScopeTest extends TestCase
{
    public function test_cases(): void
    {
        self::assertSame('cookie', Scope::Cookie->value);
        self::assertSame('ip', Scope::Ip->value);
        self::assertSame('none', Scope::None->value);
    }

    public function test_try_from_valid(): void
    {
        self::assertSame(Scope::Cookie, Scope::tryFrom('cookie'));
        self::assertSame(Scope::Ip, Scope::tryFrom('ip'));
        self::assertSame(Scope::None, Scope::tryFrom('none'));
    }

    public function test_try_from_invalid(): void
    {
        self::assertNull(Scope::tryFrom('invalid'));
        self::assertNull(Scope::tryFrom(''));
    }
}
