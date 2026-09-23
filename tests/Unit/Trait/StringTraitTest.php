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

    public function test_make_cache_key_sanitizes_invalid_chars(): void
    {
        self::assertSame('hello_world', $this->makeCacheKey('hello world'));
        self::assertSame('hello_world', $this->makeCacheKey('hello!world'));
        self::assertSame('a_b_c_d', $this->makeCacheKey('a/b@c#d'));
    }

    public function test_make_cache_key_preserves_valid_chars(): void
    {
        self::assertSame('ABC_123.abc', $this->makeCacheKey('ABC_123.abc'));
    }

    public function test_make_cache_key_truncates_long_names(): void
    {
        $long = str_repeat('a', 300);
        $result = $this->makeCacheKey($long);
        self::assertSame(128, mb_strlen($result));
    }

    public function test_make_cache_key_empty_string(): void
    {
        self::assertSame('', $this->makeCacheKey(''));
    }

    public function test_make_cache_key_with_only_invalid_chars(): void
    {
        // preg_replace with + collapses consecutive invalid chars into one _
        self::assertSame('_', $this->makeCacheKey('!!!'));
        self::assertSame('_', $this->makeCacheKey('   '));
        self::assertSame('_', $this->makeCacheKey('!@#'));
        self::assertSame('_', $this->makeCacheKey('!@ #'));
    }

    public function test_make_cache_key_truncates_to_exactly128(): void
    {
        $input = str_repeat('a', 128);
        self::assertSame(128, mb_strlen($this->makeCacheKey($input)));
        self::assertSame($input, $this->makeCacheKey($input));

        $input129 = str_repeat('a', 129);
        self::assertSame(128, mb_strlen($this->makeCacheKey($input129)));
    }

    public function test_make_cache_key_with_multibyte_chars(): void
    {
        // multibyte chars are replaced with a single underscore
        $result = $this->makeCacheKey('héllo wörld');
        // é and ö are not in [A-Za-z0-9_.] so they become _
        self::assertSame('h_llo_w_rld', $result);
    }

    public function test_make_cache_key_with_emoji(): void
    {
        $result = $this->makeCacheKey('a🎉b');
        self::assertSame('a_b', $result);
    }

    /* ── authSuccessResponse ──────────────────────────────────────────── */

    public function test_auth_success_response_session_mode(): void
    {
        $config = $this->makeConfig(remoteUserMode: 'session');
        $response = $this->authSuccessResponse('alice', $config);

        self::assertSame('hi alice', $response->getContent());
        self::assertSame('text/plain', $response->headers->get('Content-Type'));
        self::assertSame('alice', $response->headers->get('Remote-User'));
    }

    public function test_auth_success_response_static_mode(): void
    {
        $config = $this->makeConfig(remoteUserMode: 'static', remoteUserStatic: 'authenticated');
        $response = $this->authSuccessResponse('alice', $config);

        self::assertSame('hi alice', $response->getContent());
        self::assertSame('authenticated', $response->headers->get('Remote-User'));
    }

    public function test_auth_success_response_mapped_mode(): void
    {
        $config = $this->makeConfig(remoteUserMode: 'mapped', remoteUserMap: 'alice:admin');
        $response = $this->authSuccessResponse('alice', $config);

        self::assertSame('admin', $response->headers->get('Remote-User'));
    }

    public function test_auth_success_response_mapped_mode_fallback(): void
    {
        $config = $this->makeConfig(remoteUserMode: 'mapped', remoteUserMap: 'alice:admin');
        $response = $this->authSuccessResponse('unknown', $config);

        self::assertSame('unknown', $response->headers->get('Remote-User'));
    }

    public function test_auth_success_response_none_mode_omits_header(): void
    {
        $config = $this->makeConfig(remoteUserMode: 'none');
        $response = $this->authSuccessResponse('alice', $config);

        self::assertSame('hi alice', $response->getContent());
        self::assertFalse($response->headers->has('Remote-User'));
    }
}
