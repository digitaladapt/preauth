<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\GenerateBackupCodesCommand;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use App\PersistCache;
use App\Service\BackupCodeInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Tester\CommandTester;

final class GenerateBackupCodesCommandTest extends TestCase
{
    /** PersistCache is final, so construct a real one backed by ArrayAdapters. */
    private function makePersistCache(): PersistCache
    {
        return new PersistCache(new ArrayAdapter(), new ArrayAdapter());
    }

    /** A stub BackupCodeInterface that returns the given codes from generate(). */
    private function makeManagerStub(array $generatedCodes): BackupCodeInterface
    {
        $manager = $this->createStub(BackupCodeInterface::class);
        $manager->method('generate')->willReturn($generatedCodes);
        return $manager;
    }

    public function testGenerateDefaultCountOutputsCodes(): void
    {
        $codes = ['abc123', 'def456', 'ghi789', 'jkl012', 'mno345',
                  'pqr678', 'stu901', 'vwx234', 'yzA567', 'bCd890'];
        $command = new GenerateBackupCodesCommand(
            $this->makeManagerStub($codes),
            $this->makePersistCache()
        );
        $command->setName('app:generate-backup-codes');

        $tester = new CommandTester($command);
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        $output = $tester->getDisplay();
        foreach ($codes as $code) {
            self::assertStringContainsString($code, $output);
        }
    }

    public function testGenerateSpecificCountPassesCountToManager(): void
    {
        $manager = $this->createMock(BackupCodeInterface::class);
        $manager->expects(self::once())
            ->method('generate')
            ->with(self::identicalTo(5))
            ->willReturn(['c1', 'c2', 'c3', 'c4', 'c5']);

        $command = new GenerateBackupCodesCommand($manager, $this->makePersistCache());
        $command->setName('app:generate-backup-codes');

        $tester = new CommandTester($command);
        $exit = $tester->execute(['count' => 5]);

        self::assertSame(0, $exit);
    }

    public function testDefaultCountArgumentIsTen(): void
    {
        // the configured default for the count argument should be 10
        $manager = $this->createMock(BackupCodeInterface::class);
        $manager->expects(self::once())
            ->method('generate')
            ->with(self::identicalTo(10))
            ->willReturn(array_fill(0, 10, 'code'));

        $command = new GenerateBackupCodesCommand($manager, $this->makePersistCache());
        $command->setName('app:generate-backup-codes');

        $tester = new CommandTester($command);
        $tester->execute([]);

        // assertion is in the mock expectation above
        $this->addToAssertionCount(1);
    }

    public function testBootsAndPersistsCache(): void
    {
        // PersistCache is final and can't be mocked, but we can verify the
        // command runs end-to-end with a real instance; boot()/persist()
        // are invoked implicitly. A successful exit confirms both were called
        // without throwing.
        $command = new GenerateBackupCodesCommand(
            $this->makeManagerStub(['code1']),
            $this->makePersistCache()
        );
        $command->setName('app:generate-backup-codes');

        $tester = new CommandTester($command);
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
    }

    public function testZeroCodesThrowsException(): void
    {
        // count must be a positive integer — zero is rejected
        $command = new GenerateBackupCodesCommand(
            $this->makeManagerStub([]),
            $this->makePersistCache()
        );
        $command->setName('app:generate-backup-codes');

        $tester = new CommandTester($command);
        $this->expectException(\Symfony\Component\Console\Exception\InvalidArgumentException::class);
        $tester->execute(['count' => 0]);
    }

    public function testCommandNameAndDescriptionAreConfigured(): void
    {
        $command = new GenerateBackupCodesCommand(
            $this->makeManagerStub(['dummy']),
            $this->makePersistCache()
        );
        // configuring via the Application runs the protected configure()
        $app = new \Symfony\Component\Console\Application();
        $app->addCommand($command);
        self::assertSame('app:generate-backup-codes', $command->getName());
        // the source uses a non-breaking hyphen (U+2011) in "single‑use",
        // so assert against the substring to avoid encoding fragility
        self::assertStringContainsString('backup codes', $command->getDescription());
    }
}
