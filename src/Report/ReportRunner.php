<?php

namespace App\Report;

use Doctrine\DBAL\Connection;

final class ReportRunner
{
    public function __construct(private Connection $connection, private ReportRepository $reports, private ReportProcessor $processor)
    {
    }

    public function run(int $timeLimit = 45, ?string $reportId = null, ?callable $onStep = null): array
    {
        if ($timeLimit < 1)
        {
            throw new \InvalidArgumentException('Time limit must be positive.');
        }
        if (!$this->connection->fetchOne('SELECT pg_try_advisory_lock(240024, 1)'))
        {
            return ['busy' => true, 'steps' => 0];
        }
        $deadline = hrtime(true) / 1e9 + $timeLimit;
        $steps = 0;
        try
        {
            while (hrtime(true) / 1e9 < $deadline)
            {
                $id = $reportId ?? $this->reports->nextReady();
                if ($reportId !== null)
                {
                    $run = $this->reports->find($reportId);
                    if ($run === null || !in_array($run['status'], ['queued', 'running', 'waiting'], true))
                    {
                        break;
                    }
                    if ($run['retry_at'] !== null && (int) $run['retry_at'] > time())
                    {
                        if ($steps === 0 || (int) $run['retry_at'] - time() >= $deadline - hrtime(true) / 1e9)
                        {
                            break;
                        }
                        usleep(250000);
                        continue;
                    }
                }
                if ($id === null)
                {
                    $next = $this->reports->nextRetryAt();
                    if ($steps === 0 || $next === null || $next - time() >= $deadline - hrtime(true) / 1e9)
                    {
                        break;
                    }
                    usleep(250000);
                    continue;
                }
                $this->processor->step($id);
                $steps++;
                if ($onStep !== null)
                {
                    $onStep($id, $this->reports->find($id));
                }
            }
            return ['busy' => false, 'steps' => $steps];
        }
        finally
        {
            $this->connection->executeQuery('SELECT pg_advisory_unlock(240024, 1)');
        }
    }
}
