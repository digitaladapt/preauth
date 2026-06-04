<?php
declare(strict_types=1);

namespace App\Tests\Unit\Trait;

use App\Trait\StringTrait;
use PHPUnit\Framework\TestCase;

final class StringTraitTest extends TestCase {
    use StringTrait;

    public function testMakeCacheKeyWithAlphanumeric(): void {
        self::assertSame('hello', $this->makeCacheKey('hello'));
    }

    public function testMakeCacheKeyReplacesInvalidChars(): void {
        self::assertSame('hello_world', $this->makeCacheKey('hello world'));
        self::assertSame('a_b_c_', $this->makeCacheKey('a!b@c#'));
        self::assertSame('test_key', $this->makeCacheKey('test-key'));
    }

    public function testMakeCacheKeyTruncatesTo128(): void {
        $long = str_repeat('a', 200);
        self::assertSame(128, mb_strlen($this->makeCacheKey($long)));
        self::assertSame(str_repeat('a', 128), $this->makeCacheKey($long));
    }

    public function testMakeCacheKeyWithUnicode(): void {
        self::assertSame('_', $this->makeCacheKey('日本語'));
        self::assertSame('hello_world', $this->makeCacheKey('hello日本語world'));
    }

    public function testMakeCacheKeyWithDotsAndUnderscores(): void {
        self::assertSame('key.name_1', $this->makeCacheKey('key.name_1'));
    }

    public function testMakeCacheKeyEmptyString(): void {
        self::assertSame('', $this->makeCacheKey(''));
    }
}
