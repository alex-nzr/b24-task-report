<?php

namespace App\Report;

final class CsvExporter
{
    public function export(array $rows, array $run = [], array $errors = []): string
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        if ($run !== [])
        {
            $this->write($stream, ['Статус отчёта', $run['status'], $run['status'] === 'complete' ? 'Полный итог' : 'Неполный итог: сохранённые данные']);
            $this->write($stream, ['Сотрудник', $run['employee_name'], 'Период', $run['date_from'], $run['date_to'], $run['timezone']]);
        }
        fputcsv($stream, ['ID', 'Задача', 'Статус', 'Исполнитель ID', 'Роль сотрудника', 'Время', 'Секунды', 'Записей времени'], ';', '"', '');
        $total = 0;
        foreach ($rows as $row)
        {
            $cells = [$row['id'], $row['title'], $row['status'], $row['responsibleId'] ?? '', $row['role'], ReportCalculator::formatSeconds($row['seconds']), $row['seconds'], count($row['entries'])];
            $this->write($stream, $cells);
            $total += $row['seconds'];
        }
        fputcsv($stream, ['', 'Итого', '', '', '', ReportCalculator::formatSeconds($total), $total, ''], ';', '"', '');
        if ($errors !== [])
        {
            $this->write($stream, ['Ошибки', 'ID задачи', 'Задача', 'Этап', 'Код', 'Попытки', 'Состояние', 'Следующий повтор']);
            foreach ($errors as $error)
            {
                $this->write($stream, ['', $error['task_id'] ?? '', $error['task_title'] ?? '', $error['stage'], $error['error_code'], $error['attempts'], $error['state'], $error['retry_at'] ?? '']);
            }
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        return $csv;
    }

    private function write(mixed $stream, array $cells): void
    {
        $cells = array_map(static function (mixed $cell): string
        {
            $cell = (string) $cell;
            return preg_match('/^[\x00-\x20]*[=+\-@]/', $cell) ? "'".$cell : $cell;
        }, $cells);
        fputcsv($stream, $cells, ';', '"', '');
    }
}
