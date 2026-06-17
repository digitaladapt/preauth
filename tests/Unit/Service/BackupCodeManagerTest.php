<?php
declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\ConfigBag;
use App\Service\BackupCodeManager;
use App\Utilities;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class BackupCodeManagerTest extends TestCase {
    private function createConfigBag(): ConfigBag {
        $clock = $this->createMock(ClockInterface::class);
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $utilities = new Utilities($clock, $cache);
        $totp = TOTP::generate($clock);
        $totp->setLabel('Test');

        return new ConfigBag(
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
    }

    private function createManager(ArrayAdapter $pool): BackupCodeManager {
        $manager = new BackupCodeManager($pool);
        $manager->setConfig($this->createConfigBag());
        $manager->setLogger($this->createMock(LoggerInterface::class));
        return $manager;
    }

    public function testGenerateCreatesCodes(): void {
        $pool = new ArrayAdapter();
        $manager = $this->createManager($pool);
        $codes = $manager->generate(5);

        self::assertCount(5, $codes);
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^[a-z0-9]+$/', $code);
            self::assertLessThanOrEqual(BackupCodeManager::MAX_LENGTH, strlen($code));
        }
    }

    public function testGenerateCodesAreUnique(): void {
        $pool = new ArrayAdapter();
        $manager = $this->createManager($pool);
        $codes = $manager->generate(10);

        self::assertSame($codes, array_unique($codes));
    }

    public function testVerifyAndConsumeWithValidCode(): void {
        $pool = new ArrayAdapter();
        $manager = $this->createManager($pool);

        // Generate a code first
        $codes = $manager->generate(1);
        $code = $codes[0];

        self::assertTrue($manager->verifyAndConsume($code));
    }

    public function testVerifyAndConsumeWithInvalidCode(): void {
        $pool = new ArrayAdapter();
        $manager = $this->createManager($pool);

        // Generate a code and consume it first
        $codes = $manager->generate(1);
        $code = $codes[0];
        $manager->verifyAndConsume($code);

        // Now the code is used, so it should be invalid
        self::assertFalse($manager->verifyAndConsume($code));
    }

    public function testVerifyAndConsumeWithMissingCode(): void {
        $pool = new ArrayAdapter();
        $manager = $this->createManager($pool);

        self::assertFalse($manager->verifyAndConsume('nonexistent'));
    }

    public function testVerifyAndConsumeIsCaseInsensitive(): void {
        $pool = new ArrayAdapter();
        $manager = $this->createManager($pool);

        // Generate a code first
        $codes = $manager->generate(1);
        $code = $codes[0];

        self::assertTrue($manager->verifyAndConsume(strtoupper($code)));
    }

    public function testVerifyAndConsumeStripsInvalidChars(): void {
        $pool = new ArrayAdapter();
        $manager = $this->createManager($pool);

        // Generate a code first
        $codes = $manager->generate(1);
        $code = $codes[0];

        // Insert invalid characters
        $modifiedCode = substr($code, 0, 2) . '-' . substr($code, 2, 2) . ' ' . substr($code, 4);
        self::assertTrue($manager->verifyAndConsume($modifiedCode));
    }

    public function testExpireRemovesBackupCodes(): void {
        $pool = new ArrayAdapter();
        $manager = $this->createManager($pool);

        // Generate some backup codes
        $manager->generate(3);

        // Verify they exist
        $keys = [];
        foreach ($pool->getItems(['__key_list', '__chg_list']) as $item) {
            if ($item->getKey() === '__key_list') {
                $keys = array_keys($item->get() ?? []);
            }
        }
        $backupKeys = array_filter($keys, fn($k) => str_starts_with($k, 'backup_'));
        self::assertNotEmpty($backupKeys);

        // Expire them
        $manager->expire();

        // Verify they are gone
        $keysAfter = [];
        foreach ($pool->getItems(['__key_list', '__chg_list']) as $item) {
            if ($item->getKey() === '__key_list') {
                $keysAfter = array_keys($item->get() ?? []);
            }
        }
        $backupKeysAfter = array_filter($keysAfter, fn($k) => str_starts_with($k, 'backup_'));
        self::assertEmpty($backupKeysAfter);
    }

    public function testExpireDoesNothingWhenNoBackupCodes(): void {
        $pool = new ArrayAdapter();
        $manager = $this->createManager($pool);

        // Add a non-backup item
        $item = $pool->getItem('other_key');
        $item->set('value');
        $pool->save($item);

        // Expire should not throw
        $manager->expire();

        // Non-backup item should still exist
        self::assertTrue($pool->hasItem('other_key'));
    }
}
