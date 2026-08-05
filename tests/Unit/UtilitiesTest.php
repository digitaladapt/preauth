<?php
declare(strict_types=1);

namespace App\Tests\Unit;

use App\Utilities;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class UtilitiesTest extends TestCase {
    private function makeUtilities(?ArrayAdapter $pool = null, ?ClockInterface $clock = null): Utilities {
        $pool ??= new ArrayAdapter();
        $clock ??= $this->createStub(ClockInterface::class);
        return new Utilities($clock, $pool);
    }

    public function testLoadTotpReturnsCachedValueWhenPresent(): void {
        $pool = new ArrayAdapter();
        $item = $pool->getItem('totp');
        $item->set('otpauth://totp/cached?secret=ABCDEFGH');
        $pool->save($item);

        $utilities = $this->makeUtilities($pool);

        $result = $utilities->loadTotp();

        self::assertSame('otpauth://totp/cached?secret=ABCDEFGH', $result);
    }

    public function testLoadTotpGeneratesAndStoresWhenMissing(): void {
        $pool = new ArrayAdapter();
        $utilities = $this->makeUtilities($pool);

        $result = $utilities->loadTotp();

        self::assertNotEmpty($result);
        self::assertStringStartsWith('otpauth://totp/', $result);

        // stored in cache for next boot
        $cached = $pool->getItem('totp');
        self::assertTrue($cached->isHit());
        self::assertSame($result, $cached->get());
    }

    public function testLoadTotpSetsFarFutureExpiry(): void {
        $pool = new ArrayAdapter();
        $utilities = $this->makeUtilities($pool);

        $utilities->loadTotp();

        $cached = $pool->getItem('totp');
        $expiry = $cached->getMetadata()['expiry'];
        // 2999-12-31 is well in the future, far beyond any reasonable test timestamp
        self::assertGreaterThan((new \DateTimeImmutable('+10 years'))->getTimestamp(), (int) $expiry);
    }

    public function testLoadTotpIsIdempotentAfterGeneration(): void {
        $pool = new ArrayAdapter();
        $utilities = $this->makeUtilities($pool);

        $first = $utilities->loadTotp();

        // second call should find it in cache and return the same value
        $second = $utilities->loadTotp();

        self::assertSame($first, $second);
    }
}
