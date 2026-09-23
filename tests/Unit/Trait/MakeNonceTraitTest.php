<?php

declare(strict_types=1);

namespace App\Tests\Unit\Trait;

use App\Trait\MakeNonceTrait;
use DateInterval;
use DateTimeInterface;
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
final class MakeNonceTraitTest extends TestCase
{
    private function makeObject(): object
    {
        return new class {
            use MakeNonceTrait;

            public function publicMakeNonce(int $retries = 3): string
            {
                return $this->makeNonce($retries);
            }

            public function publicMakeCacheKey(string $name): string
            {
                return $this->makeCacheKey($name);
            }
        };
    }

    public function test_make_nonce_returns_base64_url_string(): void
    {
        $obj = $this->makeObject();
        $obj->setLogger(new NullLogger());
        $obj->setNonceCache(new ArrayAdapter());

        $nonce = $obj->publicMakeNonce();

        self::assertIsString($nonce);
        // 15 bytes -> 20 base64 chars without padding
        self::assertSame(20, \strlen($nonce));
        // base64url charset only
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $nonce);
    }

    public function test_make_nonce_stores_nonce_in_cache(): void
    {
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

    public function test_make_nonce_sets_expiry(): void
    {
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

    public function test_two_nonces_are_different(): void
    {
        $pool = new ArrayAdapter();
        $obj = $this->makeObject();
        $obj->setLogger(new NullLogger());
        $obj->setNonceCache($pool);

        $nonce1 = $obj->publicMakeNonce();
        $nonce2 = $obj->publicMakeNonce();

        self::assertNotSame($nonce1, $nonce2);
    }

    public function test_make_nonce_throws_after_max_retries(): void
    {
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

    public function test_make_nonce_retries_and_succeeds_after_collision(): void
    {
        // Use a spy pool that returns isHit=true on the first getItem call
        // (simulating a collision), then delegates to a real ArrayAdapter for
        // subsequent calls so the retry succeeds.
        $realPool = new ArrayAdapter();
        $collisionCount = 0;

        $spyPool = new class($realPool, $collisionCount) implements CacheItemPoolInterface {
            private int $hits = 0;

            public function __construct(
                private CacheItemPoolInterface $inner,
                private int &$hitCounter,
            ) {
            }

            public function getItem(string $key): CacheItemInterface
            {
                $item = $this->inner->getItem($key);
                // pretend the first requested key is already a hit (collision)
                if (0 === $this->hits) {
                    ++$this->hits;
                    ++$this->hitCounter;

                    return new class($key) implements CacheItemInterface {
                        public function __construct(private string $key)
                        {
                        }

                        public function getKey(): string
                        {
                            return $this->key;
                        }

                        public function get(): mixed
                        {
                            return true;
                        }

                        public function isHit(): bool
                        {
                            return true;
                        }

                        public function set(mixed $value): static
                        {
                            return $this;
                        }

                        public function expiresAt(?DateTimeInterface $expiration): static
                        {
                            return $this;
                        }

                        public function expiresAfter(int|DateInterval|null $time): static
                        {
                            return $this;
                        }
                    };
                }

                return $item;
            }

            public function getItems(array $keys = []): iterable
            {
                return $this->inner->getItems($keys);
            }

            public function hasItem(string $key): bool
            {
                return $this->inner->hasItem($key);
            }

            public function clear(): bool
            {
                return $this->inner->clear();
            }

            public function deleteItem(string $key): bool
            {
                return $this->inner->deleteItem($key);
            }

            public function deleteItems(array $keys): bool
            {
                return $this->inner->deleteItems($keys);
            }

            public function save(CacheItemInterface $item): bool
            {
                return $this->inner->save($item);
            }

            public function saveDeferred(CacheItemInterface $item): bool
            {
                return $this->inner->saveDeferred($item);
            }

            public function commit(): bool
            {
                return $this->inner->commit();
            }
        };

        $obj = $this->makeObject();
        $obj->setLogger(new NullLogger());
        $obj->setNonceCache($spyPool);

        // should retry and succeed on the second attempt
        $nonce = $obj->publicMakeNonce();
        self::assertIsString($nonce);
        self::assertSame(20, \strlen($nonce));
        self::assertSame(1, $collisionCount, 'Expected exactly one collision before success');
    }

    public function test_make_nonce_throws_immediately_with_zero_retries(): void
    {
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
        $obj->publicMakeNonce(0);
    }
}
