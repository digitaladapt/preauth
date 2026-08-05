<?php
declare(strict_types=1);

namespace App\Tests\Unit\Trait;

use App\Trait\StringTrait;
use PHPUnit\Framework\TestCase;

final class StringTraitTest extends TestCase {
    use StringTrait;

    public function testMakeCacheKeySanitizesInvalidChars(): void {
        self::assertSame('hello_world', $this->makeCacheKey('hello world'));
        self::assertSame('hello_world', $this->makeCacheKey('hello!world'));
        self::assertSame('a_b_c_d', $this->makeCacheKey('a/b@c#d'));
    }

    public function testMakeCacheKeyPreservesValidChars(): void {
        self::assertSame('ABC_123.abc', $this->makeCacheKey('ABC_123.abc'));
    }

    public function testMakeCacheKeyTruncatesLongNames(): void {
        $long = str_repeat('a', 300);
        $result = $this->makeCacheKey($long);
        self::assertSame(128, mb_strlen($result));
    }

    public function testMakeCacheKeyEmptyString(): void {
        self::assertSame('', $this->makeCacheKey(''));
    }
}
