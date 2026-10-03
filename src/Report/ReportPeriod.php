<?php

namespace App\Report;

final readonly class ReportPeriod
{
    public \DateTimeImmutable $start;
    public \DateTimeImmutable $end;

    public function __construct(public string $from, public string $to, public string $timezone)
    {
        try
        {
            $zone = new \DateTimeZone($timezone);
            $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $from, $zone);
            $last = \DateTimeImmutable::createFromFormat('!Y-m-d', $to, $zone);
            if (!$start || !$last || $start->format('Y-m-d') !== $from || $last->format('Y-m-d') !== $to || $start > $last)
            {
                throw new \InvalidArgumentException('Некорректный период отчёта.');
            }
            $this->start = $start;
            $this->end = $last->modify('+1 day');
        }
        catch (\Exception $exception)
        {
            throw new \InvalidArgumentException('Некорректные даты или часовой пояс.');
        }
    }
}
