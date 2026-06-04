<?php
declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\GenerateBackupCodesCommand;
use App\ConfigBag;
use App\MonitorCacheKeys;
use App\PersistCache;
use App\Service\BackupCodeManager;
use App\Utilities;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class GenerateBackupCodesCommandTest extends TestCase {
    private function createMockItem(string $key, mixed $value = null, bool $isHit = true): CacheItemInterface {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('getKey')->willReturn($key);
        $item->method('get')->willReturn($value);
        $item->method('isHit')->willReturn($isHit);
        $item->method('set')->willReturnSelf();
        $item->method('expiresAt')->willReturnSelf();
        return $item;
    }

    private function createMockPool(): CacheItemPoolInterface {
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);

        $pool->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);
        $pool->method('getItem')
            ->willReturnCallback(function ($key) use ($keyListItem, $changeListItem) {
                return match ($key) {
                    '__key_list' => $keyListItem,
                    '__chg_list' => $changeListItem,
                    default => $this->createMockItem($key, null, false),
                };
            });
        $pool->method('saveDeferred')->willReturn(true);
        $pool->method('commit')->willReturn(true);
        $pool->method('save')->willReturn(true);
        return $pool;
    }

    private function createBackupManager(): BackupCodeManager {
        $clock = $this->createMock(ClockInterface::class);
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $utilities = new Utilities($clock, $cache);
        $totp = TOTP::generate($clock);
        $totp->setLabel('Test');

        $config = new ConfigBag(
            $utilities,
            $clock,
            3600,
            $totp->getProvisioningUri(),
            1800,
            false,
            'Error',
            'Teapot',
            'Too Many'
        );

        $pool = $this->createMockPool();
        $manager = new BackupCodeManager($pool);
        $manager->setConfig($config);
        $manager->setLogger($this->createMock(LoggerInterface::class));
        return $manager;
    }

    private function createPersistCache(): PersistCache {
        $sessionCache = new MonitorCacheKeys($this->createMockPool());
        $sessionStorage = $this->createMockPool();
        return new PersistCache($sessionCache, $sessionStorage);
    }

    public function testExecuteWithDefaultCount(): void {
        $backupManager = $this->createBackupManager();
        $persistCache = $this->createPersistCache();

        $command = new GenerateBackupCodesCommand($backupManager, $persistCache);

        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        self::assertStringContainsString('Generated', $output);
    }

    public function testExecuteWithCustomCount(): void {
        $backupManager = $this->createBackupManager();
        $persistCache = $this->createPersistCache();

        $command = new GenerateBackupCodesCommand($backupManager, $persistCache);

        $tester = new CommandTester($command);
        $tester->execute(['count' => 5]);

        self::assertSame(0, $tester->getStatusCode());
    }

    public function testExecuteWithZeroCount(): void {
        $backupManager = $this->createBackupManager();
        $persistCache = $this->createPersistCache();

        $command = new GenerateBackupCodesCommand($backupManager, $persistCache);

        $tester = new CommandTester($command);
        $tester->execute(['count' => 0]);

        self::assertSame(0, $tester->getStatusCode());
    }
}
