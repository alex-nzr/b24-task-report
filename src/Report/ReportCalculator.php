<?php

namespace App\Report;

final class ReportCalculator
{
    public function summarize(array $entries, int $employeeId, ReportPeriod $period): array
    {
        $selected = [];
        $seconds = 0;
        foreach ($entries as $entry)
        {
            foreach (['ID', 'TASK_ID', 'USER_ID'] as $field)
            {
                if (!isset($entry[$field]) || !preg_match('/^[1-9]\d*$/D', (string) $entry[$field]) || filter_var($entry[$field], FILTER_VALIDATE_INT) === false)
                {
                    throw new \UnexpectedValueException('Некорректный идентификатор записи времени.');
                }
            }
            if (!isset($entry['CREATED_DATE'], $entry['SECONDS']) || !preg_match('/^\d+$/D', (string) $entry['SECONDS']) || filter_var($entry['SECONDS'], FILTER_VALIDATE_INT) === false || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $entry['CREATED_DATE']))
            {
                throw new \UnexpectedValueException('Некорректная запись времени.');
            }
            try
            {
                $date = new \DateTimeImmutable($entry['CREATED_DATE']);
                if ($date->format('Y-m-d\TH:i:sP') !== preg_replace('/Z$/', '+00:00', $entry['CREATED_DATE']))
                {
                    throw new \UnexpectedValueException('Некорректная календарная дата записи времени.');
                }
            }
            catch (\Exception)
            {
                throw new \UnexpectedValueException('Некорректная дата записи времени.');
            }
            if ((int) $entry['USER_ID'] !== $employeeId || $date < $period->start || $date >= $period->end)
            {
                continue;
            }
            $key = $entry['TASK_ID'].':'.$entry['ID'];
            if (!isset($selected[$key]))
            {
                $selected[$key] = $entry;
                if ($seconds > PHP_INT_MAX - (int) $entry['SECONDS'])
                {
                    throw new \UnexpectedValueException('Сумма времени превышает допустимый размер.');
                }
                $seconds += (int) $entry['SECONDS'];
            }
        }
        return ['seconds' => $seconds, 'entries' => array_values($selected)];
    }

    public function role(array $task, int $employeeId): string
    {
        if (!isset($task['responsible']['id']) || !array_key_exists('accomplices', $task) || !array_key_exists('auditors', $task) || !is_array($task['accomplices']) || !is_array($task['auditors']))
        {
            throw new \UnexpectedValueException('Не получены участники задачи.');
        }
        if ((int) $task['responsible']['id'] === $employeeId)
        {
            return 'Исполнитель';
        }
        foreach (['accomplices' => 'Соисполнитель', 'auditors' => 'Наблюдатель'] as $field => $label)
        {
            foreach ($task[$field] as $user)
            {
                if (!isset($user['id']))
                {
                    throw new \UnexpectedValueException('Некорректный участник задачи.');
                }
                if ((int) $user['id'] === $employeeId)
                {
                    return $label;
                }
            }
        }
        return 'Без роли';
    }

    public static function formatSeconds(int $seconds): string
    {
        return sprintf('%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}
