<?php

namespace App\Report;

use App\Bitrix\BitrixClient;
use App\Bitrix\BitrixFailure;

final class TaskReportCollector
{
    public function __construct(private BitrixClient $bitrix, private ReportCalculator $calculator)
    {
    }

    public function collectTask(array $task, int $employeeId, ReportPeriod $period): ?array
    {
        $id = (int) $task['id'];
        try
        {
            $summary = $this->calculator->summarize($this->bitrix->timeEntries($id, $employeeId, $period), $employeeId, $period);
        }
        catch (BitrixFailure $failure)
        {
            throw new TaskCollectionFailure('time_entries', $failure);
        }
        catch (\UnexpectedValueException|\InvalidArgumentException)
        {
            throw new TaskCollectionFailure('time_entries', new BitrixFailure('INVALID_REPORT_DATA'));
        }
        if ($summary['entries'] === [])
        {
            return null;
        }
        $row = ['id' => $id, 'title' => $task['title'], 'status' => $task['status'], 'responsibleId' => (int) $task['responsibleId'], 'role' => 'Не удалось определить'] + $summary;
        if (array_key_exists('accomplices', $task))
        {
            $row['role'] = $row['responsibleId'] === $employeeId ? 'Исполнитель' : (in_array($employeeId, $task['accomplices'], true) ? 'Соисполнитель' : 'Без роли');
            return $row;
        }
        return $this->resolveRole($row, $employeeId);
    }

    public function resolveRole(array $row, int $employeeId): array
    {
        if ($row['responsibleId'] === $employeeId)
        {
            $row['role'] = 'Исполнитель';
            return $row;
        }
        try
        {
            $row['role'] = $this->calculator->role($this->bitrix->participants((int) $row['id']), $employeeId);
        }
        catch (BitrixFailure $failure)
        {
            throw new TaskCollectionFailure('participants', $failure, $row);
        }
        catch (\UnexpectedValueException|\InvalidArgumentException)
        {
            throw new TaskCollectionFailure('participants', new BitrixFailure('INVALID_REPORT_DATA'), $row);
        }
        return $row;
    }
}
