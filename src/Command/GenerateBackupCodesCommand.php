<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\BackupCodeManager;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Simple console command to generate backup codes.
 *
 * Usage: php bin/console app:generate-backup-codes [count]
 */
final class GenerateBackupCodesCommand extends Command {
    // Symfony will use this name if not overridden in configure().
    protected static string $defaultName = 'app:generate-backup-codes';

    private BackupCodeManager $manager;

    public function __construct(BackupCodeManager $manager) {
        parent::__construct();
        $this->manager = $manager;
    }

    protected function configure(): void {
        // Explicitly set the command name to avoid empty‑name errors on older Symfony versions.
        $this->setName('app:generate-backup-codes');
        $this
            ->setDescription('Generate single‑use backup codes')
            ->addArgument('count', InputArgument::OPTIONAL, 'Number of codes to generate', 10);
    }

    /** @throws InvalidArgumentException */
    protected function execute(InputInterface $input, OutputInterface $output): int {
        $count = (int) $input->getArgument('count');
        $codes = $this->manager->generate($count);
        foreach ($codes as $code) {
            $output->writeln($code);
        }
        return Command::SUCCESS;
    }
}
