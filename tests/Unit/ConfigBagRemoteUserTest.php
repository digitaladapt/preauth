<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Enum\RemoteUserMode;
use App\Tests\Support\TotpTestHelper;
use PHPUnit\Framework\TestCase;

final class ConfigBagRemoteUserTest extends TestCase
{
    use TotpTestHelper;

    public function test_default_remote_user_mode_is_session(): void
    {
        $config = $this->makeConfig();

        self::assertSame(RemoteUserMode::Session, $config->remoteUserMode());
    }

    public function test_static_mode(): void
    {
        $config = $this->makeConfig(remoteUserMode: 'static', remoteUserStatic: 'authenticated');

        self::assertSame(RemoteUserMode::Static, $config->remoteUserMode());
        self::assertSame('authenticated', $config->remoteUserStatic());
    }

    public function test_mapped_mode(): void
    {
        $config = $this->makeConfig(remoteUserMode: 'mapped', remoteUserMap: 'alice:admin,bob:user');

        self::assertSame(RemoteUserMode::Mapped, $config->remoteUserMode());
        self::assertSame(['alice' => 'admin', 'bob' => 'user'], $config->remoteUserMap());
    }

    public function test_none_mode(): void
    {
        $config = $this->makeConfig(remoteUserMode: 'none');

        self::assertSame(RemoteUserMode::None, $config->remoteUserMode());
    }

    public function test_invalid_mode_falls_back_to_session(): void
    {
        $config = $this->makeConfig(remoteUserMode: 'invalid-mode');

        self::assertSame(RemoteUserMode::Session, $config->remoteUserMode());
    }

    public function test_empty_map_returns_empty_array(): void
    {
        $config = $this->makeConfig(remoteUserMode: 'mapped', remoteUserMap: '');

        self::assertSame([], $config->remoteUserMap());
    }

    public function test_map_parses_with_whitespace(): void
    {
        $config = $this->makeConfig(
            remoteUserMode: 'mapped',
            remoteUserMap: ' alice : admin , bob : user ',
        );

        self::assertSame(['alice' => 'admin', 'bob' => 'user'], $config->remoteUserMap());
    }

    public function test_map_ignores_invalid_entries(): void
    {
        $config = $this->makeConfig(
            remoteUserMode: 'mapped',
            remoteUserMap: 'alice:admin,noColon,bob:user',
        );

        self::assertSame(['alice' => 'admin', 'bob' => 'user'], $config->remoteUserMap());
    }

    public function test_map_preserves_colons_in_value(): void
    {
        $config = $this->makeConfig(
            remoteUserMode: 'mapped',
            remoteUserMap: 'alice:admin:extra',
        );

        self::assertSame(['alice' => 'admin:extra'], $config->remoteUserMap());
    }
}
