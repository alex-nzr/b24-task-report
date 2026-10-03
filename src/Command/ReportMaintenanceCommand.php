<?php

namespace App\Command;

use App\Report\ReportRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:reports:purge', description: 'Remove terminal reports, including partial results and errors, older than seven days')]
final class ReportMaintenanceCommand extends Command
{
    public function __construct(private ReportRepository $reports)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Удалено отчётов: '.$this->reports->purgeExpired());
        return Command::SUCCESS;
    }
}
