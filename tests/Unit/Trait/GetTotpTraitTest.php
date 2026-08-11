<?php

declare(strict_types=1);

namespace App\Tests\Unit\Trait;

use App\ConfigBag;
use App\Tests\Support\TotpTestHelper;
use App\Trait\GetTotpTrait;
use OTPHP\TOTPInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class GetTotpTraitTest extends TestCase
{
    use TotpTestHelper;

    private function makeObject(): object
    {
        return new class () {
            use GetTotpTrait;

            public function publicGetTotp(): TOTPInterface
            {
                return $this->getTotp();
            }
        };
    }

    public function testSetConfigSetsProperty(): void
    {
        $obj = $this->makeObject();
        $config = $this->makeConfig();

        $obj->setConfig($config);

        $reflection = new \ReflectionProperty($obj, 'config');
        self::assertSame($config, $reflection->getValue($obj));
    }

    public function testGetTotpReturnsTotpInterface(): void
    {
        $obj = $this->makeObject();
        $obj->setConfig($this->makeConfig());

        $totp = $obj->publicGetTotp();

        self::assertInstanceOf(TOTPInterface::class, $totp);
    }

    public function testGetTotpReturnsValidCode(): void
    {
        $obj = $this->makeObject();
        $obj->setConfig($this->makeConfig());

        $totp = $obj->publicGetTotp();

        // the code at the frozen time should match our helper
        self::assertSame($this->validTotpCode(), $totp->now());
    }

    public function testGetTotpThrowsOnInvalidUri(): void
    {
        $obj = $this->makeObject();
        $clock = $this->frozenClock();
        $utilities = $this->createUtilities($clock);
        $config = new ConfigBag(
            $utilities,
            $clock,
            3600,
            'not-a-valid-uri',
            0,
            false,
            'Error',
            'Teapot',
            'Too Many'
        );
        $obj->setConfig($config);

        // Factory::loadFromProvisioningUri throws InvalidProvisioningUriException
        // which is not caught by getTotp() since the instanceof check only runs
        // after a successful load — so we expect a Throwable here
        $this->expectException(\Throwable::class);
        $obj->publicGetTotp();
    }

    public function testGetTotpThrowsHttpExceptionWhenNotTotpType(): void
    {
        // A HOTP URI loads successfully as an OTPInterface but is NOT a TOTPInterface,
        // so the instanceof check in getTotp() should throw an HttpException(500)
        $obj = $this->makeObject();
        $clock = $this->frozenClock();
        $utilities = $this->createUtilities($clock);
        $config = new ConfigBag(
            $utilities,
            $clock,
            3600,
            'otpauth://hotp/Test-HOTP?secret=JBSWY3DPEHPK3PXP&counter=0',
            0,
            false,
            'Error',
            'Teapot',
            'Too Many'
        );
        $obj->setConfig($config);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Internal Server Exception');
        $obj->publicGetTotp();
    }
}
