<?php
declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\ConfigBag;
use App\Service\BackupCodeManager;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final class BackupCodeManagerTest extends TestCase {
    private function createMockPool(): CacheItemPoolInterface {
        return $this->createMock(CacheItemPoolInterface::class);
    }

    private function createMockItem(string $key, mixed $value = null, bool $isHit = true): CacheItemInterface {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('getKey')->willReturn($key);
        $item->method('get')->willReturn($value);
        $item->method('isHit')->willReturn($isHit);
        $item->method('set')->willReturnSelf();
        $item->method('expiresAt')->willReturnSelf();
        return $item;
    }

    private function createConfigBag(): ConfigBag {
        $clock = $this->createMock(ClockInterface::class);
        $utilities = $this->createMock(\App\Utilities::class);
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

    private function createManager(CacheItemPoolInterface $pool): BackupCodeManager {
        $manager = new BackupCodeManager($pool);
        $manager->setConfig($this->createConfigBag());
        $manager->setLogger($this->createMock(LoggerInterface::class));
        return $manager;
    }

    public function testGenerateCreatesCodes(): void {
        $pool = $this->createMockPool();
        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);

        $pool->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);
        $pool->method('getItem')
            ->willReturnCallback(function ($key) {
                return $this->createMockItem($key, null, false);
            });
        $pool->expects(self::atLeastOnce())->method('saveDeferred')->willReturn(true);
        $pool->expects(self::atLeastOnce())->method('commit')->willReturn(true);

        $manager = $this->createManager($pool);
        $codes = $manager->generate(5);

        self::assertCount(5, $codes);
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^[a-z0-9]+$/', $code);
            self::assertLessThanOrEqual(BackupCodeManager::MAX_LENGTH, strlen($code));
        }
    }

    public function testGenerateCodesAreUnique(): void {
        $pool = $this->createMockPool();
        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);

        $pool->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);
        $pool->method('getItem')
            ->willReturnCallback(function ($key) {
                return $this->createMockItem($key, null, false);
            });
        $pool->method('saveDeferred')->willReturn(true);
        $pool->method('commit')->willReturn(true);

        $manager = $this->createManager($pool);
        $codes = $manager->generate(10);

        self::assertSame($codes, array_unique($codes));
    }

    public function testVerifyAndConsumeWithValidCode(): void {
        $pool = $this->createMockPool();
        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);
        $backupItem = $this->createMockItem('backup_abc123', true, true);

        $pool->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);
        $pool->method('getItem')
            ->willReturnCallback(function ($key) use ($backupItem) {
                if (str_contains($key, 'backup_')) {
                    return $backupItem;
                }
                return $this->createMockItem($key, null, false);
            });
        $pool->expects(self::atLeastOnce())->method('save')->willReturn(true);

        $manager = $this->createManager($pool);
        self::assertTrue($manager->verifyAndConsume('abc123'));
    }

    public function testVerifyAndConsumeWithInvalidCode(): void {
        $pool = $this->createMockPool();
        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);
        $backupItem = $this->createMockItem('backup_invalid', false, true);

        $pool->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);
        $pool->method('getItem')
            ->willReturnCallback(function ($key) use ($backupItem) {
                if (str_contains($key, 'backup_')) {
                    return $backupItem;
                }
                return $this->createMockItem($key, null, false);
            });

        $manager = $this->createManager($pool);
        self::assertFalse($manager->verifyAndConsume('invalid'));
    }

    public function testVerifyAndConsumeWithMissingCode(): void {
        $pool = $this->createMockPool();
        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);
        $backupItem = $this->createMockItem('backup_missing', null, false);

        $pool->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);
        $pool->method('getItem')
            ->willReturnCallback(function ($key) use ($backupItem) {
                if (str_contains($key, 'backup_')) {
                    return $backupItem;
                }
                return $this->createMockItem($key, null, false);
            });

        $manager = $this->createManager($pool);
        self::assertFalse($manager->verifyAndConsume('missing'));
    }

    public function testVerifyAndConsumeIsCaseInsensitive(): void {
        $pool = $this->createMockPool();
        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);
        $backupItem = $this->createMockItem('backup_abc123', true, true);

        $pool->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);
        $pool->method('getItem')
            ->willReturnCallback(function ($key) use ($backupItem) {
                if (str_contains($key, 'backup_')) {
                    return $backupItem;
                }
                return $this->createMockItem($key, null, false);
            });
        $pool->expects(self::atLeastOnce())->method('save')->willReturn(true);

        $manager = $this->createManager($pool);
        self::assertTrue($manager->verifyAndConsume('ABC123'));
        self::assertTrue($manager->verifyAndConsume('AbC123'));
    }

    public function testVerifyAndConsumeStripsInvalidChars(): void {
        $pool = $this->createMockPool();
        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);
        $backupItem = $this->createMockItem('backup_abc123', true, true);

        $pool->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);
        $pool->method('getItem')
            ->willReturnCallback(function ($key) use ($backupItem) {
                if (str_contains($key, 'backup_')) {
                    return $backupItem;
                }
                return $this->createMockItem($key, null, false);
            });
        $pool->expects(self::atLeastOnce())->method('save')->willReturn(true);

        $manager = $this->createManager($pool);
        self::assertTrue($manager->verifyAndConsume('abc-123'));
        self::assertTrue($manager->verifyAndConsume('abc 123'));
        self::assertTrue($manager->verifyAndConsume('abc!@#123'));
    }

    public function testExpireRemovesBackupCodes(): void {
        $pool = $this->createMockPool();
        $keyListItem = $this->createMockItem('__key_list', ['backup_a' => true, 'backup_b' => true, 'other' => true], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);

        $pool->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);
        $pool->method('getItem')
            ->with('__key_list')
            ->willReturn($keyListItem);
        $pool->expects(self::once())
            ->method('deleteItems')
            ->with(self::callback(function ($keys) {
                return count($keys) === 2 && in_array('backup_a', $keys) && in_array('backup_b', $keys);
            }))
            ->willReturn(true);
        $pool->expects(self::once())->method('saveDeferred')->willReturn(true);
        $pool->expects(self::once())->method('commit')->willReturn(true);

        $manager = $this->createManager($pool);
        $manager->expire();
    }

    public function testExpireDoesNothingWhenNoBackupCodes(): void {
        $pool = $this->createMockPool();
        $keyListItem = $this->createMockItem('__key_list', ['other' => true], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);

        $pool->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);
        $pool->method('getItem')
            ->with('__key_list')
            ->willReturn($keyListItem);
        $pool->expects(self::never())->method('deleteItems');

        $manager = $this->createManager($pool);
        $manager->expire();
    }
}
