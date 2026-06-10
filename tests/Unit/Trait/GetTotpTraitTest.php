<?php

namespace App\Tests\Unit\Trait;

use App\ConfigBag;
use App\Trait\GetTotpTrait;
use App\Utilities;
use OTPHP\TOTP;
use OTPHP\TOTPInterface;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

class GetTotpTraitTest extends TestCase {
    use GetTotpTrait;

    private function createConfigBag(string $totpUri): ConfigBag {
        $clock = $this->createStub(ClockInterface::class);
        $cache = $this->createStub(CacheItemPoolInterface::class);
        $utilities = new Utilities($clock, $cache);

        return new ConfigBag(
            $utilities,
            $clock,
            3600,
            $totpUri,
            1800,
            true,
            'Error!',
            'Teapot!',
            'Too Many!'
        );
    }

    /** will create the totp instance from the config */
    public function testGetTotpFromConfig(): void {
        $clock = $this->createStub(ClockInterface::class);
        $totp = TOTP::generate($clock);
        $totp->setLabel('test');

        $config = $this->createConfigBag($totp->getProvisioningUri());
        $this->setConfig($config);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method($this->anything());
        $this->setLogger($logger);

        $this->assertInstanceOf(TOTPInterface::class, $this->getTotp());
        $this->assertSame($totp->getProvisioningUri(), $this->getTotp()->getProvisioningUri());
        /* uri should match, but it will not be the same object instances */
        $this->assertNotSame($totp, $this->getTotp());
    }

    /** will emit pretty error, if config is invalid */
    public function testGetTotpInvalidConfig(): void {
        $config = $this->createConfigBag('otpauth://invalid-totp-uri');
        $this->setConfig($config);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('emergency');
        $this->setLogger($logger);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Internal Server Error');

        $this->getTotp();
    }
}
