<?php

namespace App\Report;

use App\Bitrix\BitrixClient;
use App\Bitrix\BitrixFailure;
use Doctrine\DBAL\Connection;

final class ReportProcessor
{
    public function __construct(private Connection $connection, private ReportRepository $reports, private BitrixClient $bitrix, private TaskReportCollector $collector)
    {
    }

    // Caller holds the global collection lock. Each step commits progress independently.
    public function step(string $id): void
    {
        $run = $this->reports->find($id);
        if (!$run || !in_array($run['status'], ['queued', 'running', 'waiting'], true) || ($run['retry_at'] !== null && (int) $run['retry_at'] > time()))
        {
            return;
        }
        $this->reports->update($id, ['last_processed_at' => date(DATE_ATOM)]);
        $task = null;
        $stage = 'task_list';
        try
        {
            $period = new ReportPeriod($run['date_from'], $run['date_to'], $run['timezone']);
            if ($run['max_id'] === null)
            {
                $initial = $this->bitrix->selectedTasks((int) $run['employee_id'], $period, 0, null, true);
                $last = $initial['tasks'];
                if ($last !== [] && (!isset($last[0]['id']) || filter_var($last[0]['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false))
                {
                    throw new BitrixFailure('INVALID_TASK_PAGE');
                }
                $run['max_id'] = $last ? (int) $last[0]['id'] : 0;
                $this->reports->update($id, ['max_id' => $run['max_id'], 'total_tasks' => $initial['total'], 'started_at' => date(DATE_ATOM)]);
            }
            $tasks = $run['pending_tasks'];
            if ($tasks === [])
            {
                $tasks = $this->bitrix->selectedTasks((int) $run['employee_id'], $period, (int) $run['cursor_id'], (int) $run['max_id'])['tasks'];
                $this->reports->update($id, ['pending_tasks' => $tasks]);
            }
            $this->reports->resolveErrors($id, null, 'task_list');
            $this->reports->update($id, ['status' => 'running', 'retry_at' => null, 'error_code' => null]);
            if ($tasks === [])
            {
                $this->reports->update($id, ['status' => $this->reports->hasUnresolvedErrors($id) ? 'complete_with_errors' : 'complete', 'finished_at' => date(DATE_ATOM), 'attempts' => 0]);
                return;
            }
            $task = $tasks[0];
            if (!isset($task['id'], $task['title'], $task['status'], $task['responsibleId']) || (int) $task['id'] <= (int) $run['cursor_id'] || (int) $task['id'] > (int) $run['max_id'])
            {
                $task = null;
                throw new BitrixFailure('INVALID_TASK_PAGE');
            }
            $stage = 'time_entries';
            $pendingRole = array_filter($this->reports->errors($id), static fn (array $error): bool => $error['task_id'] === (int) $task['id'] && $error['stage'] === 'participants' && $error['state'] === 'pending');
            $knownRow = $pendingRole !== [] ? $this->reports->taskRow($id, (int) $task['id']) : null;
            $row = $knownRow !== null
                ? $this->collector->resolveRole($knownRow, (int) $run['employee_id'])
                : $this->collector->collectTask($task, (int) $run['employee_id'], $period);
            $this->connection->transactional(function () use ($id, $run, $tasks, $task, $row): void
            {
                if ($row !== null)
                {
                    $this->reports->saveTask($id, $row);
                }
                $this->reports->resolveErrors($id, (int) $task['id']);
                $this->advance($id, $run, $tasks);
            });
        }
        catch (TaskCollectionFailure $failure)
        {
            $this->recordFailure($id, $run, $task, $failure->stage, $failure->failure, $failure->row);
        }
        catch (BitrixFailure $failure)
        {
            $this->recordFailure($id, $run, $task, $stage, $failure);
        }
        catch (\UnexpectedValueException|\InvalidArgumentException)
        {
            $this->recordFailure($id, $run, $task, $stage, new BitrixFailure('INVALID_REPORT_DATA'));
        }
    }

    private function recordFailure(string $id, array $run, ?array $task, string $stage, BitrixFailure $failure, ?array $row = null): void
    {
        $prior = array_filter($this->reports->errors($id), static fn (array $error): bool => $error['task_id'] === ($task === null ? null : (int) $task['id']) && $error['stage'] === $stage);
        $previous = $prior === [] ? 0 : (int) reset($prior)['attempts'];
        $attempt = $previous + 1;
        $methodLimit = $failure->errorCode === 'OPERATION_TIME_LIMIT';
        $budget = ($prior !== [] && reset($prior)['state'] === 'pending' ? (int) $run['attempts'] : 0) + ($methodLimit ? 0 : 1);
        $retry = $failure->retryAt !== null && ($methodLimit || $budget < 5);
        $retryAt = $retry ? max($failure->retryAt, time() + min(60, 2 ** min(max(1, $budget), 6))) : null;
        $this->connection->transactional(function () use ($id, $run, $task, $stage, $failure, $row, $attempt, $budget, $retry, $retryAt): void
        {
            if ($row !== null)
            {
                $this->reports->saveTask($id, $row);
                $this->reports->resolveErrors($id, (int) $task['id'], 'time_entries');
            }
            $this->reports->saveError($id, $task, $stage, $failure->errorCode, $attempt, $retry ? 'pending' : 'exhausted', $retryAt);
            if ($retry)
            {
                $this->reports->update($id, ['status' => 'waiting', 'retry_at' => $retryAt, 'error_code' => $failure->errorCode, 'attempts' => $budget]);
            }
            elseif ($task !== null)
            {
                $current = $this->reports->find($id);
                $this->advance($id, $run, $current['pending_tasks']);
            }
            else
            {
                $this->reports->update($id, ['status' => 'failed', 'retry_at' => null, 'error_code' => $failure->errorCode, 'attempts' => $budget, 'finished_at' => date(DATE_ATOM)]);
            }
        });
    }

    private function advance(string $id, array $run, array $tasks): void
    {
        $task = array_shift($tasks);
        $this->reports->update($id, ['pending_tasks' => $tasks, 'cursor_id' => (int) $task['id'], 'checked_tasks' => (int) $run['checked_tasks'] + 1, 'attempts' => 0, 'status' => 'running', 'retry_at' => null, 'error_code' => null]);
    }
}
