<?php
declare(strict_types=1);

namespace App\Tests\Unit\Core;

use App\Clock;
use PHPUnit\Framework\TestCase;

final class ClockTest extends TestCase {
    public function testNowReturnsDateTimeImmutable(): void {
        $clock = new Clock();
        $before = new \DateTimeImmutable();
        $now = $clock->now();
        $after = new \DateTimeImmutable();

        self::assertInstanceOf(\DateTimeImmutable::class, $now);
        self::assertGreaterThanOrEqual($before->getTimestamp(), $now->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $now->getTimestamp());
    }

    public function testNowReturnsDifferentInstances(): void {
        $clock = new Clock();
        $first = $clock->now();
        usleep(1000);
        $second = $clock->now();

        self::assertNotSame($first, $second);
    }
}
