<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Clock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ClockTest extends TestCase
{
    public function test_now_returns_date_time_immutable(): void
    {
        $clock = new Clock();
        $before = new DateTimeImmutable();
        $now = $clock->now();
        $after = new DateTimeImmutable();

        self::assertInstanceOf(DateTimeImmutable::class, $now);
        self::assertGreaterThanOrEqual($before->getTimestamp(), $now->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $now->getTimestamp());
    }
}
