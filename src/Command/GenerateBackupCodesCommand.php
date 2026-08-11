<?php

declare(strict_types=1);

namespace App\Command;

use App\PersistCache;
use App\Service\BackupCodeInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** simple console command to generate backup codes
 * usage: php bin/console app:generate-backup-codes [count] */
final class GenerateBackupCodesCommand extends Command
{
    public function __construct(
        private readonly BackupCodeInterface $manager,
        private readonly PersistCache        $persistCache,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('app:generate-backup-codes');
        $this->setDescription('Generate single‑use backup codes')
            ->addArgument('count', InputArgument::OPTIONAL, 'Number of codes to generate', 10);
    }

    /** @throws InvalidArgumentException */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /* since Kernel::terminate() does not get called, we must boot and persist explicitly */
        $this->persistCache->boot();
        $count = (int) $input->getArgument('count');
        $codes = $this->manager->generate($count);
        foreach ($codes as $code) {
            $output->writeln($code);
        }
        $this->persistCache->persist();
        return Command::SUCCESS;
    }
}
