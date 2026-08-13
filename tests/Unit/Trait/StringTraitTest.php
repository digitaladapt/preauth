<?php

declare(strict_types=1);

namespace App\Tests\Unit\Trait;

use App\Tests\Support\TotpTestHelper;
use App\Trait\StringTrait;
use PHPUnit\Framework\TestCase;

final class StringTraitTest extends TestCase
{
    use StringTrait;
    use TotpTestHelper;

    public function testMakeCacheKeySanitizesInvalidChars(): void
    {
        self::assertSame('hello_world', $this->makeCacheKey('hello world'));
        self::assertSame('hello_world', $this->makeCacheKey('hello!world'));
        self::assertSame('a_b_c_d', $this->makeCacheKey('a/b@c#d'));
    }

    public function testMakeCacheKeyPreservesValidChars(): void
    {
        self::assertSame('ABC_123.abc', $this->makeCacheKey('ABC_123.abc'));
    }

    public function testMakeCacheKeyTruncatesLongNames(): void
    {
        $long = str_repeat('a', 300);
        $result = $this->makeCacheKey($long);
        self::assertSame(128, mb_strlen($result));
    }

    public function testMakeCacheKeyEmptyString(): void
    {
        self::assertSame('', $this->makeCacheKey(''));
    }

    public function testMakeCacheKeyWithOnlyInvalidChars(): void
    {
        // preg_replace with + collapses consecutive invalid chars into one _
        self::assertSame('_', $this->makeCacheKey('!!!'));
        self::assertSame('_', $this->makeCacheKey('   '));
        self::assertSame('_', $this->makeCacheKey('!@#'));
        self::assertSame('_', $this->makeCacheKey('!@ #'));
    }

    public function testMakeCacheKeyTruncatesToExactly128(): void
    {
        $input = str_repeat('a', 128);
        self::assertSame(128, mb_strlen($this->makeCacheKey($input)));
        self::assertSame($input, $this->makeCacheKey($input));

        $input129 = str_repeat('a', 129);
        self::assertSame(128, mb_strlen($this->makeCacheKey($input129)));
    }

    public function testMakeCacheKeyWithMultibyteChars(): void
    {
        // multibyte chars are replaced with a single underscore
        $result = $this->makeCacheKey('héllo wörld');
        // é and ö are not in [A-Za-z0-9_.] so they become _
        self::assertSame('h_llo_w_rld', $result);
    }

    public function testMakeCacheKeyWithEmoji(): void
    {
        $result = $this->makeCacheKey('a🎉b');
        self::assertSame('a_b', $result);
    }

    /* ── authSuccessResponse ──────────────────────────────────────────── */

    public function testAuthSuccessResponseSessionMode(): void
    {
        $config = $this->makeConfig(remoteUserMode: 'session');
        $response = $this->authSuccessResponse('alice', $config);

        self::assertSame('hi alice', $response->getContent());
        self::assertSame('text/plain', $response->headers->get('Content-Type'));
        self::assertSame('alice', $response->headers->get('Remote-User'));
    }

    public function testAuthSuccessResponseStaticMode(): void
    {
        $config = $this->makeConfig(remoteUserMode: 'static', remoteUserStatic: 'authenticated');
        $response = $this->authSuccessResponse('alice', $config);

        self::assertSame('hi alice', $response->getContent());
        self::assertSame('authenticated', $response->headers->get('Remote-User'));
    }

    public function testAuthSuccessResponseMappedMode(): void
    {
        $config = $this->makeConfig(remoteUserMode: 'mapped', remoteUserMap: 'alice:admin');
        $response = $this->authSuccessResponse('alice', $config);

        self::assertSame('admin', $response->headers->get('Remote-User'));
    }

    public function testAuthSuccessResponseMappedModeFallback(): void
    {
        $config = $this->makeConfig(remoteUserMode: 'mapped', remoteUserMap: 'alice:admin');
        $response = $this->authSuccessResponse('unknown', $config);

        self::assertSame('unknown', $response->headers->get('Remote-User'));
    }

    public function testAuthSuccessResponseNoneModeOmitsHeader(): void
    {
        $config = $this->makeConfig(remoteUserMode: 'none');
        $response = $this->authSuccessResponse('alice', $config);

        self::assertSame('hi alice', $response->getContent());
        self::assertFalse($response->headers->has('Remote-User'));
    }
}
