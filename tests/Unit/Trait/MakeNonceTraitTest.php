<?php
declare(strict_types=1);

namespace App\Tests\Unit\Trait;

use App\Trait\MakeNonceTrait;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Wraps the trait in a concrete class with public proxies so the protected
 * methods can be exercised from test scope.
 */
final class MakeNonceTraitTest extends TestCase {
    private function makeObject(): object {
        return new class {
            use MakeNonceTrait;

            public function publicMakeNonce(int $retries = 3): string {
                return $this->makeNonce($retries);
            }

            public function publicMakeCacheKey(string $name): string {
                return $this->makeCacheKey($name);
            }
        };
    }

    public function testMakeNonceReturnsBase64UrlString(): void {
        $obj = $this->makeObject();
        $obj->setLogger(new NullLogger());
        $obj->setNonceCache(new ArrayAdapter());

        $nonce = $obj->publicMakeNonce();

        self::assertIsString($nonce);
        // 15 bytes -> 20 base64 chars without padding
        self::assertSame(20, strlen($nonce));
        // base64url charset only
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $nonce);
    }

    public function testMakeNonceStoresNonceInCache(): void {
        $pool = new ArrayAdapter();
        $obj = $this->makeObject();
        $obj->setLogger(new NullLogger());
        $obj->setNonceCache($pool);

        $nonce = $obj->publicMakeNonce();

        // makeNonce stores via makeCacheKey() which rewrites '-' to '_'
        $key = $obj->publicMakeCacheKey($nonce);
        self::assertTrue($pool->hasItem($key));
        $item = $pool->getItem($key);
        self::assertTrue($item->get());
    }

    public function testMakeNonceSetsExpiry(): void {
        $pool = new ArrayAdapter();
        $obj = $this->makeObject();
        $obj->setLogger(new NullLogger());
        $obj->setNonceCache($pool);

        $nonce = $obj->publicMakeNonce();

        $item = $pool->getItem($obj->publicMakeCacheKey($nonce));
        $expiry = $item->getMetadata()['expiry'];
        // NONCE_TTL is 120 seconds
        self::assertLessThanOrEqual(120, (int) $expiry - time());
        self::assertGreaterThan(time(), (int) $expiry);
    }

    public function testTwoNoncesAreDifferent(): void {
        $pool = new ArrayAdapter();
        $obj = $this->makeObject();
        $obj->setLogger(new NullLogger());
        $obj->setNonceCache($pool);

        $nonce1 = $obj->publicMakeNonce();
        $nonce2 = $obj->publicMakeNonce();

        self::assertNotSame($nonce1, $nonce2);
    }

    public function testMakeNonceThrowsAfterMaxRetries(): void {
        // Create a stub pool that always reports every key as a hit (collision)
        $pool = $this->createStub(CacheItemPoolInterface::class);
        $item = $this->createStub(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn(true);
        $pool->method('getItem')->willReturn($item);
        $pool->method('save')->willReturn(true);

        $obj = $this->makeObject();
        $obj->setLogger(new NullLogger());
        $obj->setNonceCache($pool);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Internal Server Error');

        $obj->publicMakeNonce();
    }
}
