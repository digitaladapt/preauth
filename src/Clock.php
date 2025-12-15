<?php
declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/* A new Clock should be made for each request,
 * because a Clock is a snapshot of when the request was made. */
#[AsAlias(ClockInterface::class)]
class Clock implements ClockInterface {
    private DateTimeImmutable $date;

    public function now(): DateTimeImmutable {
        return new DateTimeImmutable();
    }
}
