<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ConfigBag;
use App\Utilities;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;

final class ConfigBagTest extends TestCase
{
    private function createUtilities(?string $totp = null): Utilities
    {
        $clock = $this->createStub(ClockInterface::class);
        $cache = $this->createStub(CacheItemPoolInterface::class);

        if ($totp !== null) {
            $item = $this->createStub(CacheItemInterface::class);
            $item->method('isHit')->willReturn(true);
            $item->method('get')->willReturn($totp);
            $cache->method('hasItem')->willReturn(true);
            $cache->method('getItem')->willReturn($item);
        } else {
            $cache->method('hasItem')->willReturn(false);
        }

        return new Utilities($clock, $cache);
    }

    public function testGettersWithExplicitValues(): void
    {
        $clock = $this->createStub(ClockInterface::class);
        $utilities = $this->createUtilities();

        $config = new ConfigBag(
            $utilities,
            $clock,
            3600,
            'otpauth://totp/test',
            1800,
            true,
            'Error!',
            'Teapot!',
            'Too Many!',
            'session',
            'authenticated',
            '',
        );

        self::assertSame($clock, $config->clock());
        self::assertSame(3600, $config->cookieTtl());
        self::assertSame('otpauth://totp/test', $config->totpUri());
        self::assertSame(1800, $config->ipTtl());
        self::assertTrue($config->teapot());
        self::assertSame('Error!', $config->errorMessage());
        self::assertSame('Teapot!', $config->teapotTitle());
        self::assertSame('Too Many!', $config->tooManyTitle());
    }

    public function testTotpUriFallsBackToUtilitiesWhenEmpty(): void
    {
        $clock = $this->createStub(ClockInterface::class);
        $utilities = $this->createUtilities('fallback-totp');

        $config = new ConfigBag(
            $utilities,
            $clock,
            3600,
            '',
            1800,
            false,
            'Error',
            'Teapot',
            'Too Many',
            'session',
            'authenticated',
            '',
        );

        self::assertSame('fallback-totp', $config->totpUri());
    }

    public function testIpTtlFallsBackToNullWhenZero(): void
    {
        $clock = $this->createStub(ClockInterface::class);
        $utilities = $this->createUtilities();

        $config = new ConfigBag(
            $utilities,
            $clock,
            3600,
            'otpauth://totp/test',
            0,
            false,
            'Error',
            'Teapot',
            'Too Many',
            'session',
            'authenticated',
            '',
        );

        self::assertNull($config->ipTtl());
    }

    public function testIpTtlFallsBackToNullWhenNull(): void
    {
        $clock = $this->createStub(ClockInterface::class);
        $utilities = $this->createUtilities();

        $config = new ConfigBag(
            $utilities,
            $clock,
            3600,
            'otpauth://totp/test',
            null,
            false,
            'Error',
            'Teapot',
            'Too Many',
            'session',
            'authenticated',
            '',
        );

        self::assertNull($config->ipTtl());
    }
}
