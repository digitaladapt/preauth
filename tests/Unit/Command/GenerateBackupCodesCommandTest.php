<?php
declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\GenerateBackupCodesCommand;
use App\ConfigBag;
use App\PersistCache;
use App\Service\BackupCodeManager;
use App\Utilities;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Tester\CommandTester;

final class GenerateBackupCodesCommandTest extends TestCase {
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

        $pool = new ArrayAdapter();
        $manager = new BackupCodeManager($pool);
        $manager->setConfig($config);
        $manager->setLogger($this->createMock(LoggerInterface::class));
        return $manager;
    }

    private function createPersistCache(): PersistCache {
        $sessionCache = new ArrayAdapter();
        $sessionStorage = new ArrayAdapter();
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
        self::assertNotEmpty($output);
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
