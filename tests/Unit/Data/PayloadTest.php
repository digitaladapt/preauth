<?php
declare(strict_types=1);

namespace App\Tests\Unit\Data;

use App\Data\Payload;
use App\Enum\Scope;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

final class PayloadTest extends TestCase {
    public function testDecodeValidBase64Url(): void {
        $data = json_encode([
            'id'    => 'testuser',
            'token' => '123456',
            'nonce' => 'abc123',
            'json'  => true,
            'scope' => 'cookie',
        ]);
        $base64url = rtrim(strtr(base64_encode($data), '+/', '-_'), '=');

        $payload = Payload::decode($base64url);

        self::assertInstanceOf(Payload::class, $payload);
        self::assertSame('testuser', $payload->id);
        self::assertSame('123456', $payload->token);
        self::assertSame('abc123', $payload->nonce);
        self::assertTrue($payload->json);
        self::assertSame(Scope::Cookie, $payload->scope);
    }

    public function testDecodeInvalidBase64UrlReturnsNull(): void {
        self::assertNull(Payload::decode('!!!not-valid-base64!!!'));
    }

    public function testDecodeNonObjectJsonReturnsNull(): void {
        $base64url = rtrim(strtr(base64_encode('"just a string"'), '+/', '-_'), '=');
        self::assertNull(Payload::decode($base64url));
    }

    public function testDecodeEmptyStringReturnsNull(): void {
        self::assertNull(Payload::decode(''));
    }

    public function testLoadWithValidInputBag(): void {
        $input = new InputBag([
            'username' => 'alice',
            'nonce'    => 'nonce123',
            'totp'     => '654321',
        ]);

        $payload = Payload::load($input);

        self::assertInstanceOf(Payload::class, $payload);
        self::assertSame('alice', $payload->id);
        self::assertSame('nonce123', $payload->nonce);
        self::assertSame('654321', $payload->token);
        self::assertFalse($payload->json);
        self::assertSame(Scope::Cookie, $payload->scope);
    }

    public function testLoadMissingUsernameReturnsNull(): void {
        $input = new InputBag([
            'nonce' => 'nonce123',
            'totp'  => '654321',
        ]);
        self::assertNull(Payload::load($input));
    }

    public function testLoadMissingNonceReturnsNull(): void {
        $input = new InputBag([
            'username' => 'alice',
            'totp'     => '654321',
        ]);
        self::assertNull(Payload::load($input));
    }

    public function testLoadMissingTotpReturnsNull(): void {
        $input = new InputBag([
            'username' => 'alice',
            'nonce'    => 'nonce123',
        ]);
        self::assertNull(Payload::load($input));
    }

    public function testLoadEmptyInputBagReturnsNull(): void {
        $input = new InputBag([]);
        self::assertNull(Payload::load($input));
    }

    public function testCreateWithValidData(): void {
        $data = (object)[
            'id'    => 'user1',
            'token' => 'tok1',
            'nonce' => 'non1',
            'json'  => false,
            'scope' => 'ip',
        ];

        $payload = Payload::create($data);

        self::assertInstanceOf(Payload::class, $payload);
        self::assertSame('user1', $payload->id);
        self::assertSame('tok1', $payload->token);
        self::assertSame('non1', $payload->nonce);
        self::assertFalse($payload->json);
        self::assertSame(Scope::Ip, $payload->scope);
    }

    public function testCreateWithDefaultScope(): void {
        $data = (object)[
            'id'    => 'user1',
            'token' => 'tok1',
            'nonce' => 'non1',
        ];

        $payload = Payload::create($data);

        self::assertSame(Scope::Cookie, $payload->scope);
    }

    public function testCreateWithNoneScopeSetsJsonFalse(): void {
        $data = (object)[
            'id'    => 'user1',
            'token' => 'tok1',
            'nonce' => 'non1',
            'json'  => true,
            'scope' => 'none',
        ];

        $payload = Payload::create($data);

        self::assertSame(Scope::None, $payload->scope);
        self::assertFalse($payload->json);
    }

    public function testCreateWithEmptyIdReturnsNull(): void {
        $data = (object)[
            'id'    => '',
            'token' => 'tok1',
            'nonce' => 'non1',
        ];
        self::assertNull(Payload::create($data));
    }

    public function testCreateWithWhitespaceIdReturnsNull(): void {
        $data = (object)[
            'id'    => '   ',
            'token' => 'tok1',
            'nonce' => 'non1',
        ];
        self::assertNull(Payload::create($data));
    }

    public function testCreateWithEmptyTokenReturnsNull(): void {
        $data = (object)[
            'id'    => 'user1',
            'token' => '',
            'nonce' => 'non1',
        ];
        self::assertNull(Payload::create($data));
    }

    public function testCreateWithEmptyNonceReturnsNull(): void {
        $data = (object)[
            'id'    => 'user1',
            'token' => 'tok1',
            'nonce' => '',
        ];
        self::assertNull(Payload::create($data));
    }

    public function testCreateTrimsAndTruncatesFields(): void {
        $long = str_repeat('a', 200);
        $data = (object)[
            'id'    => '  ' . $long . '  ',
            'token' => '  ' . $long . '  ',
            'nonce' => '  ' . $long . '  ',
        ];

        $payload = Payload::create($data);

        self::assertSame(mb_substr($long, 0, 128), $payload->id);
        self::assertSame(mb_substr($long, 0, 128), $payload->token);
        self::assertSame(mb_substr($long, 0, 128), $payload->nonce);
    }

    public function testToString(): void {
        $payload = new Payload();
        $payload->id    = 'u';
        $payload->token = 't';
        $payload->nonce = 'n';
        $payload->json  = true;
        $payload->scope = Scope::Cookie;

        $json = $payload->toString();
        $decoded = json_decode($json, true);

        self::assertSame('u', $decoded['id']);
        self::assertSame('t', $decoded['token']);
        self::assertSame('n', $decoded['nonce']);
        self::assertTrue($decoded['json']);
        self::assertSame('cookie', $decoded['scope']);
    }
}
