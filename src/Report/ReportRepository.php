<?php

namespace App\Report;

use Doctrine\DBAL\Connection;

final class ReportRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function create(array $employee, ReportPeriod $period): string
    {
        $id = bin2hex(random_bytes(16));
        $this->connection->insert('report_runs', ['id' => $id, 'employee_id' => $employee['id'], 'employee_name' => $employee['name'], 'date_from' => $period->from, 'date_to' => $period->to, 'timezone' => $period->timezone]);
        return $id;
    }

    public function find(string $id): ?array
    {
        $run = $this->connection->fetchAssociative('SELECT * FROM report_runs WHERE id = ?', [$id]);
        if (!$run)
        {
            return null;
        }
        $run['pending_tasks'] = json_decode($run['pending_tasks'], true, 512, JSON_THROW_ON_ERROR);
        return $run;
    }

    public function latest(): array
    {
        return $this->connection->fetchAllAssociative('SELECT id, employee_name, date_from, date_to, status, created_at FROM report_runs ORDER BY created_at DESC LIMIT 20');
    }

    public function update(string $id, array $changes): void
    {
        if (isset($changes['pending_tasks']))
        {
            $changes['pending_tasks'] = json_encode($changes['pending_tasks'], JSON_THROW_ON_ERROR);
        }
        $this->connection->update('report_runs', $changes, ['id' => $id]);
    }

    public function saveTask(string $id, array $row): void
    {
        $this->connection->executeStatement('INSERT INTO report_tasks(run_id, task_id, data) VALUES (?, ?, ?::jsonb) ON CONFLICT(run_id, task_id) DO UPDATE SET data = EXCLUDED.data', [$id, $row['id'], json_encode($row, JSON_THROW_ON_ERROR)]);
    }

    public function rows(string $id): array
    {
        return array_map(static fn (string $json): array => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $this->connection->fetchFirstColumn('SELECT data FROM report_tasks WHERE run_id = ? ORDER BY task_id', [$id]));
    }

    public function taskRow(string $id, int $taskId): ?array
    {
        $value = $this->connection->fetchOne('SELECT data FROM report_tasks WHERE run_id = ? AND task_id = ?', [$id, $taskId]);
        return $value === false ? null : json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    public function purgeExpired(): int
    {
        return $this->connection->executeStatement("DELETE FROM report_runs WHERE created_at < CURRENT_TIMESTAMP - INTERVAL '7 days' AND status IN ('complete', 'complete_with_errors', 'failed')");
    }

    public function delete(string $id): string
    {
        if (!$this->connection->fetchOne('SELECT pg_try_advisory_lock(240024, 1)'))
        {
            return 'busy';
        }
        try
        {
            return $this->connection->delete('report_runs', ['id' => $id]) ? 'deleted' : 'missing';
        }
        finally
        {
            $this->connection->executeQuery('SELECT pg_advisory_unlock(240024, 1)');
        }
    }

    public function nextReady(): ?string
    {
        $id = $this->connection->fetchOne("SELECT id FROM report_runs WHERE status IN ('queued', 'running', 'waiting') AND (retry_at IS NULL OR retry_at <= ?) ORDER BY COALESCE(last_processed_at, created_at), created_at, id LIMIT 1", [time()]);
        return $id === false ? null : $id;
    }

    public function nextRetryAt(): ?int
    {
        $value = $this->connection->fetchOne("SELECT MIN(retry_at) FROM report_runs WHERE status IN ('queued', 'running', 'waiting') AND retry_at > ?", [time()]);
        return $value === null || $value === false ? null : (int) $value;
    }

    public function saveError(string $id, ?array $task, string $stage, string $code, int $attempts, string $state, ?int $retryAt): void
    {
        $this->connection->executeStatement('INSERT INTO report_errors (run_id, task_id, task_title, stage, error_code, attempts, state, retry_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT (run_id, task_id, stage) DO UPDATE SET task_title = EXCLUDED.task_title, error_code = EXCLUDED.error_code, attempts = EXCLUDED.attempts, state = EXCLUDED.state, retry_at = EXCLUDED.retry_at, updated_at = CURRENT_TIMESTAMP', [$id, $task['id'] ?? null, $task['title'] ?? null, $stage, $code, $attempts, $state, $retryAt]);
    }

    public function errors(string $id): array
    {
        return $this->connection->fetchAllAssociative('SELECT id, task_id, task_title, stage, error_code, attempts, state, retry_at, created_at, updated_at FROM report_errors WHERE run_id = ? ORDER BY id', [$id]);
    }

    public function resolveErrors(string $id, ?int $taskId, ?string $stage = null): void
    {
        $sql = "UPDATE report_errors SET state = 'resolved', retry_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE run_id = ? AND task_id IS NOT DISTINCT FROM ? AND state = 'pending'";
        $values = [$id, $taskId];
        if ($stage !== null)
        {
            $sql .= ' AND stage = ?';
            $values[] = $stage;
        }
        $this->connection->executeStatement($sql, $values);
    }

    public function hasUnresolvedErrors(string $id): bool
    {
        return (bool) $this->connection->fetchOne("SELECT EXISTS (SELECT 1 FROM report_errors WHERE run_id = ? AND state <> 'resolved')", [$id]);
    }
}
