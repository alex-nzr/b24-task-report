<?php

namespace App\Command;

use App\Report\ReportRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:reports:collect', description: 'Collect ready reports and exit; default time budget is 45 seconds')]
final class ReportCollectCommand extends Command
{
    public function __construct(private ReportRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Soft time budget in seconds, checked between task steps', 45);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var($input->getOption('time-limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($limit === false)
        {
            $output->writeln('<error>time-limit должен быть положительным целым числом.</error>');
            return Command::INVALID;
        }
        try
        {
            $result = $this->runner->run($limit, onStep: static function (string $id, array $run) use ($output): void
            {
                $output->writeln(sprintf('Отчёт %s: %s; проверено задач: %d%s', $id, $run['status'], $run['checked_tasks'], $run['error_code'] ? '; ошибка: '.$run['error_code'] : ''));
            });
            if ($result['busy'])
            {
                $output->writeln('Сбор уже выполняется другим процессом.');
                return Command::SUCCESS;
            }
            $output->writeln('Обработано шагов: '.$result['steps'].'. Сохранённый прогресс доступен в отчёте.');
            return Command::SUCCESS;
        }
        catch (\Throwable)
        {
            $output->writeln('<error>COLLECTION_COMMAND_FAILED: сбор остановлен. Сохранённый прогресс не удалён; повторите команду после проверки БД и конфигурации.</error>');
            return Command::FAILURE;
        }
    }
}
