<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\ConfigBag;
use App\Utilities;
use DateTimeImmutable;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface as PsrClockInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Provides a deterministic TOTP fixture plus a frozen clock and ready-made
 * ConfigBag / cache-pool helpers for tests that exercise TOTP-dependent code.
 */
trait TotpTestHelper
{
    /** well-known Base32 test secret (JBSWY3DPEHPK3PXP) */
    private const string TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

    /** Frozen timestamp used for deterministic TOTP codes. */
    protected const string FROZEN_TIME = '2025-06-15 12:00:00';

    /** Frozen clock that always returns the same instant. */
    private function frozenClock(): PsrClockInterface
    {
        $time = self::FROZEN_TIME;
        return new class ($time) implements PsrClockInterface {
            public function __construct(private string $time)
            {
            }
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable($this->time);
            }
        };
    }

    /** Provisioning URI built from the well-known secret + frozen clock. */
    private function totpUri(): string
    {
        $totp = TOTP::createFromSecret(self::TOTP_SECRET, $this->frozenClock());
        $totp->setLabel('Test-TOTP');
        return $totp->getProvisioningUri();
    }

    /** The TOTP code that is valid at the frozen timestamp. */
    private function validTotpCode(): string
    {
        return TOTP::createFromSecret(self::TOTP_SECRET, $this->frozenClock())->now();
    }

    /** A fresh in-memory cache pool suitable for wrapping in MonitorCacheKeys. */
    private function emptyPool(): CacheItemPoolInterface
    {
        return new ArrayAdapter();
    }

    /**
     * Build a ConfigBag wired with the deterministic TOTP and frozen clock.
     * Extra params override the sensible defaults.
     */
    private function makeConfig(
        ?int $cookieTtl = 3600,
        ?int $ipTtl = 0,
        bool $teapot = true,
        string $errorMessage = 'Error',
        string $teapotTitle = 'Teapot',
        string $tooManyTitle = 'Too Many',
    ): ConfigBag {
        $clock = $this->frozenClock();
        $utilities = $this->createUtilities($clock);
        return new ConfigBag(
            $utilities,
            $clock,
            $cookieTtl,
            $this->totpUri(),
            $ipTtl,
            $teapot,
            $errorMessage,
            $teapotTitle,
            $tooManyTitle,
        );
    }

    /**
     * Minimal Utilities stub that never triggers TOTP generation when
     * a non-empty totpUri is supplied to ConfigBag.
     */
    private function createUtilities(?PsrClockInterface $clock = null): Utilities
    {
        $clock ??= $this->frozenClock();
        $cache = $this->createStub(CacheItemPoolInterface::class);
        $cache->method('hasItem')->willReturn(false);
        $item = $this->createStub(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);
        $cache->method('getItem')->willReturn($item);
        return new Utilities($clock, $cache);
    }
}
