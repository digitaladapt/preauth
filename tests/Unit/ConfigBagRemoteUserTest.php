<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ConfigBag;
use App\Enum\RemoteUserMode;
use App\Tests\Support\TotpTestHelper;
use PHPUnit\Framework\TestCase;

final class ConfigBagRemoteUserTest extends TestCase
{
    use TotpTestHelper;

    public function testDefaultRemoteUserModeIsSession(): void
    {
        $config = $this->makeConfig();

        self::assertSame(RemoteUserMode::Session, $config->remoteUserMode());
    }

    public function testStaticMode(): void
    {
        $config = $this->makeConfig(remoteUserMode: 'static', remoteUserStatic: 'authenticated');

        self::assertSame(RemoteUserMode::Static, $config->remoteUserMode());
        self::assertSame('authenticated', $config->remoteUserStatic());
    }

    public function testMappedMode(): void
    {
        $config = $this->makeConfig(remoteUserMode: 'mapped', remoteUserMap: 'alice:admin,bob:user');

        self::assertSame(RemoteUserMode::Mapped, $config->remoteUserMode());
        self::assertSame(['alice' => 'admin', 'bob' => 'user'], $config->remoteUserMap());
    }

    public function testNoneMode(): void
    {
        $config = $this->makeConfig(remoteUserMode: 'none');

        self::assertSame(RemoteUserMode::None, $config->remoteUserMode());
    }

    public function testInvalidModeFallsBackToSession(): void
    {
        $config = $this->makeConfig(remoteUserMode: 'invalid-mode');

        self::assertSame(RemoteUserMode::Session, $config->remoteUserMode());
    }

    public function testEmptyMapReturnsEmptyArray(): void
    {
        $config = $this->makeConfig(remoteUserMode: 'mapped', remoteUserMap: '');

        self::assertSame([], $config->remoteUserMap());
    }

    public function testMapParsesWithWhitespace(): void
    {
        $config = $this->makeConfig(
            remoteUserMode: 'mapped',
            remoteUserMap: ' alice : admin , bob : user ',
        );

        self::assertSame(['alice' => 'admin', 'bob' => 'user'], $config->remoteUserMap());
    }

    public function testMapIgnoresInvalidEntries(): void
    {
        $config = $this->makeConfig(
            remoteUserMode: 'mapped',
            remoteUserMap: 'alice:admin,noColon,bob:user',
        );

        self::assertSame(['alice' => 'admin', 'bob' => 'user'], $config->remoteUserMap());
    }

    public function testMapPreservesColonsInValue(): void
    {
        $config = $this->makeConfig(
            remoteUserMode: 'mapped',
            remoteUserMap: 'alice:admin:extra',
        );

        self::assertSame(['alice' => 'admin:extra'], $config->remoteUserMap());
    }
}
