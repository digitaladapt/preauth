<?php

declare(strict_types=1);

namespace App\Tests\Unit\Data;

use App\Data\Payload;
use App\Enum\Scope;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

final class PayloadTest extends TestCase
{
    private static function b64u(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public function test_decode_valid_base64_url(): void
    {
        $data = json_encode([
            'id' => 'testuser', 'token' => '123456', 'nonce' => 'abc123',
            'json' => true, 'scope' => 'cookie',
        ]);
        $payload = Payload::decode(self::b64u($data));

        self::assertInstanceOf(Payload::class, $payload);
        self::assertSame('testuser', $payload->id);
        self::assertSame('123456', $payload->token);
        self::assertSame('abc123', $payload->nonce);
        self::assertTrue($payload->json);
        self::assertSame(Scope::Cookie, $payload->scope);
    }

    public function test_decode_invalid_base64_url_returns_null(): void
    {
        self::assertNull(Payload::decode('!!!not-valid-base64!!!'));
    }

    public function test_decode_non_object_json_returns_null(): void
    {
        self::assertNull(Payload::decode(self::b64u('"just a string"')));
    }

    public function test_decode_invalid_json_returns_null(): void
    {
        // valid base64url but invalid JSON
        self::assertNull(Payload::decode(self::b64u('{invalid json')));
    }

    public function test_decode_json_array_returns_null(): void
    {
        self::assertNull(Payload::decode(self::b64u('[1,2,3]')));
    }

    public function test_decode_json_null_returns_null(): void
    {
        self::assertNull(Payload::decode(self::b64u('null')));
    }

    public function test_decode_json_boolean_returns_null(): void
    {
        self::assertNull(Payload::decode(self::b64u('true')));
        self::assertNull(Payload::decode(self::b64u('false')));
    }

    public function test_decode_json_number_returns_null(): void
    {
        self::assertNull(Payload::decode(self::b64u('42')));
    }

    public function test_decode_empty_string_returns_null(): void
    {
        self::assertNull(Payload::decode(''));
    }

    public function test_load_with_valid_input_bag(): void
    {
        $input = new InputBag([
            'username' => 'alice', 'nonce' => 'nonce123', 'totp' => '654321',
        ]);
        $payload = Payload::load($input);

        self::assertInstanceOf(Payload::class, $payload);
        self::assertSame('alice', $payload->id);
        self::assertSame('nonce123', $payload->nonce);
        self::assertSame('654321', $payload->token);
        self::assertFalse($payload->json);
        self::assertSame(Scope::Cookie, $payload->scope);
    }

    public function test_load_missing_username_returns_null(): void
    {
        $input = new InputBag(['nonce' => 'n', 'totp' => 't']);
        self::assertNull(Payload::load($input));
    }

    public function test_load_missing_nonce_returns_null(): void
    {
        $input = new InputBag(['username' => 'u', 'totp' => 't']);
        self::assertNull(Payload::load($input));
    }

    public function test_load_missing_totp_returns_null(): void
    {
        $input = new InputBag(['username' => 'u', 'nonce' => 'n']);
        self::assertNull(Payload::load($input));
    }

    public function test_load_with_all_fields_present_but_empty_returns_null(): void
    {
        // has() returns true for all, but create() rejects empty values
        $input = new InputBag(['username' => '', 'nonce' => '', 'totp' => '']);
        self::assertNull(Payload::load($input));
    }

    public function test_create_with_valid_data(): void
    {
        $data = (object) [
            'id' => 'user1', 'token' => 'tok1', 'nonce' => 'non1',
            'json' => false, 'scope' => 'ip',
        ];
        $payload = Payload::create($data);

        self::assertInstanceOf(Payload::class, $payload);
        self::assertSame('user1', $payload->id);
        self::assertSame('tok1', $payload->token);
        self::assertSame('non1', $payload->nonce);
        self::assertFalse($payload->json);
        self::assertSame(Scope::Ip, $payload->scope);
    }

    public function test_create_with_default_scope(): void
    {
        $data = (object) ['id' => 'user1', 'token' => 'tok1', 'nonce' => 'non1'];
        $payload = Payload::create($data);
        self::assertSame(Scope::Cookie, $payload->scope);
    }

    public function test_create_with_invalid_scope_falls_back_to_cookie(): void
    {
        $data = (object) [
            'id' => 'user1', 'token' => 'tok1', 'nonce' => 'non1',
            'scope' => 'admin',
        ];
        $payload = Payload::create($data);
        self::assertSame(Scope::Cookie, $payload->scope);
    }

    public function test_create_with_missing_json_defaults_to_true(): void
    {
        $data = (object) ['id' => 'user1', 'token' => 'tok1', 'nonce' => 'non1'];
        $payload = Payload::create($data);
        self::assertTrue($payload->json);
    }

    public function test_create_with_none_scope_sets_json_false(): void
    {
        $data = (object) [
            'id' => 'user1', 'token' => 'tok1', 'nonce' => 'non1',
            'json' => true, 'scope' => 'none',
        ];
        $payload = Payload::create($data);
        self::assertSame(Scope::None, $payload->scope);
        self::assertFalse($payload->json);
    }

    public function test_create_with_empty_id_returns_null(): void
    {
        $data = (object) ['id' => '', 'token' => 't', 'nonce' => 'n'];
        self::assertNull(Payload::create($data));
    }

    public function test_create_with_whitespace_id_returns_null(): void
    {
        $data = (object) ['id' => '   ', 'token' => 't', 'nonce' => 'n'];
        self::assertNull(Payload::create($data));
    }

    public function test_create_with_empty_token_returns_null(): void
    {
        $data = (object) ['id' => 'u', 'token' => '', 'nonce' => 'n'];
        self::assertNull(Payload::create($data));
    }

    public function test_create_with_empty_nonce_returns_null(): void
    {
        $data = (object) ['id' => 'u', 'token' => 't', 'nonce' => ''];
        self::assertNull(Payload::create($data));
    }

    public function test_create_trims_and_truncates_fields(): void
    {
        $long = str_repeat('a', 200);
        $data = (object) [
            'id' => '  '.$long.'  ',
            'token' => '  '.$long.'  ',
            'nonce' => '  '.$long.'  ',
        ];
        $payload = Payload::create($data);
        $expected = mb_substr($long, 0, 128);
        self::assertSame($expected, $payload->id);
        self::assertSame($expected, $payload->token);
        self::assertSame($expected, $payload->nonce);
    }

    public function test_to_string(): void
    {
        $payload = new Payload();
        $payload->id = 'u';
        $payload->token = 't';
        $payload->nonce = 'n';
        $payload->json = true;
        $payload->scope = Scope::Cookie;

        $decoded = json_decode($payload->toString(), true);
        self::assertSame('u', $decoded['id']);
        self::assertSame('t', $decoded['token']);
        self::assertSame('n', $decoded['nonce']);
        self::assertTrue($decoded['json']);
        self::assertSame('cookie', $decoded['scope']);
    }
}
