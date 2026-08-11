<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\BackupCodeManager;
use App\Tests\Support\TotpTestHelper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class BackupCodeManagerTest extends TestCase
{
    use TotpTestHelper;

    private function makeManager(?ArrayAdapter $pool = null): BackupCodeManager
    {
        $pool ??= new ArrayAdapter();
        $manager = new BackupCodeManager($pool);
        $manager->setConfig($this->makeConfig());
        $manager->setLogger(new NullLogger());
        return $manager;
    }

    public function testGenerateReturnsRequestedCount(): void
    {
        $manager = $this->makeManager();

        $codes = $manager->generate(5);

        self::assertCount(5, $codes);
        foreach ($codes as $code) {
            self::assertIsString($code);
            // codes are lowercase alphanumeric
            self::assertMatchesRegularExpression('/^[a-z0-9]+$/', $code);
        }
    }

    public function testGenerateDefaultCount(): void
    {
        $manager = $this->makeManager();

        $codes = $manager->generate();

        self::assertCount(10, $codes);
    }

    public function testGenerateZeroReturnsEmptyArray(): void
    {
        $manager = $this->makeManager();

        $codes = $manager->generate(0);

        self::assertSame([], $codes);
    }

    public function testGeneratedCodesAreStoredInCache(): void
    {
        $pool = new ArrayAdapter();
        $manager = $this->makeManager($pool);

        $codes = $manager->generate(3);

        // each code should be stored as a backup_ key
        foreach ($codes as $code) {
            $key = 'backup_' . strtolower($code);
            // the manager uses makeCacheKey which sanitizes, but for alphanumeric it's identity
            $item = $pool->getItem($key);
            self::assertTrue($item->isHit(), "Expected cache hit for key: $key");
            self::assertTrue($item->get(), "Expected code to be marked valid (true)");
        }
    }

    public function testGeneratedCodesHaveFarFutureExpiry(): void
    {
        $pool = new ArrayAdapter();
        $manager = $this->makeManager($pool);

        $codes = $manager->generate(1);
        $code = $codes[0];

        $item = $pool->getItem('backup_' . strtolower($code));
        $expiry = $item->getMetadata()['expiry'];
        self::assertGreaterThan((new \DateTimeImmutable('+10 years'))->getTimestamp(), (int) $expiry);
    }

    public function testVerifyAndConsumeValidCode(): void
    {
        $manager = $this->makeManager();
        $codes = $manager->generate(2);

        $code = $codes[0];

        self::assertTrue($manager->verifyAndConsume($code));
    }

    public function testVerifyAndConsumeMarksCodeAsUsed(): void
    {
        $pool = new ArrayAdapter();
        $manager = $this->makeManager($pool);
        $codes = $manager->generate(1);
        $code = $codes[0];

        // first use succeeds
        self::assertTrue($manager->verifyAndConsume($code));

        // second use fails (already consumed)
        self::assertFalse($manager->verifyAndConsume($code));
    }

    public function testVerifyAndConsumeInvalidCode(): void
    {
        $manager = $this->makeManager();

        self::assertFalse($manager->verifyAndConsume('nonexistent_code'));
    }

    public function testVerifyAndConsumeIsCaseInsensitive(): void
    {
        $manager = $this->makeManager();
        $codes = $manager->generate(1);
        $code = $codes[0];

        // uppercase version should still work
        self::assertTrue($manager->verifyAndConsume(strtoupper($code)));
    }

    public function testVerifyAndConsumeStripsInvalidCharacters(): void
    {
        $manager = $this->makeManager();
        $codes = $manager->generate(1);
        $code = $codes[0];

        // inject spaces and special chars — should be stripped
        self::assertTrue($manager->verifyAndConsume('  ' . $code . '!!'));
    }

    public function testExpireRemovesAllBackupCodes(): void
    {
        $pool = new ArrayAdapter();
        $manager = $this->makeManager($pool);
        $codes = $manager->generate(5);

        $manager->expire();

        // all backup keys should be gone
        foreach ($codes as $code) {
            self::assertFalse($pool->hasItem('backup_' . strtolower($code)));
        }
    }

    public function testExpireWhenNoBackupCodesIsNoop(): void
    {
        $pool = new ArrayAdapter();
        $manager = $this->makeManager($pool);

        // should not throw
        $manager->expire();

        // this passes if no exception was thrown
        self::assertTrue(true);
    }

    public function testExpireRemovesOnlyBackupPrefixedKeys(): void
    {
        $pool = new ArrayAdapter();
        $manager = $this->makeManager($pool);

        $codes = $manager->generate(3);

        // add a non-backup key
        $item = $pool->getItem('cookie_session');
        $item->set('data');
        $pool->save($item);

        $manager->expire();

        // non-backup key survives
        self::assertTrue($pool->hasItem('cookie_session'));

        // backup keys are gone
        foreach ($codes as $code) {
            self::assertFalse($pool->hasItem('backup_' . strtolower($code)));
        }
    }

    public function testVerifyAndConsumeEmptyStringReturnsFalse(): void
    {
        $manager = $this->makeManager();

        // empty string after preg_replace becomes 'backup_' with nothing after it
        self::assertFalse($manager->verifyAndConsume(''));
    }

    public function testVerifyAndConsumeCodeWithValueFalseReturnsFalse(): void
    {
        $pool = new ArrayAdapter();
        $manager = $this->makeManager($pool);
        $codes = $manager->generate(1);
        $code = $codes[0];

        // first use succeeds
        self::assertTrue($manager->verifyAndConsume($code));

        // the code is now marked as false (used); isHit is true but get() is false
        $key = 'backup_' . strtolower($code);
        $item = $pool->getItem($key);
        self::assertTrue($item->isHit());
        self::assertFalse($item->get());

        // second use should fail because get() returns false
        self::assertFalse($manager->verifyAndConsume($code));
    }

    public function testGenerateProducesUniqueCodes(): void
    {
        $manager = $this->makeManager();

        $codes = $manager->generate(50);

        self::assertCount(50, $codes);
        self::assertCount(50, array_unique($codes), 'All generated codes should be unique');
    }

    public function testGenerateCodeLengthIsDigitsPlusTwo(): void
    {
        $manager = $this->makeManager();

        $codes = $manager->generate(1);

        // default TOTP digits is 6, so code length should be 6 + 2 = 8
        self::assertSame(8, strlen($codes[0]));
    }
}
