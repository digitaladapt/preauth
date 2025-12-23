<?php
declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ClockInterface::class)]
final readonly class Clock implements ClockInterface {
    public function now(): DateTimeImmutable {
        return new DateTimeImmutable();
    }
}
