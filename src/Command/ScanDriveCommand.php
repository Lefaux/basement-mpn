<?php

namespace App\Command;

use App\Service\GoogleDriveScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(name: 'app:scan-drive', description: 'Scan the configured Google Shared Drive')]
final class ScanDriveCommand extends Command
{
    public function __construct(private readonly GoogleDriveScanner $scanner, private readonly LockFactory $lockFactory) { parent::__construct(); }
    protected function configure(): void { $this->addOption('full', null, InputOption::VALUE_NONE, 'Perform a complete recursive audit'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lock = $this->lockFactory->createLock('music-drive-scan', 600);
        if (!$lock->acquire()) { $output->writeln('A scan is already running; skipped.'); return Command::SUCCESS; }
        try {
            if (!$input->getOption('full')) { $output->writeln(sprintf('Processed %d Drive changes.', $this->scanner->syncIncremental())); return Command::SUCCESS; }
            $output->writeln(sprintf('Indexed %d Ableton projects.', $this->scanner->syncFull()));
            return Command::SUCCESS;
        } finally { $lock->release(); }
    }
}
