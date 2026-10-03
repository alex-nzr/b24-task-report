<?php

namespace App\Report;

use App\Bitrix\BitrixClient;
use App\Bitrix\BitrixFailure;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class WorkingDays
{
    public function __construct(private BitrixClient $bitrix, private CacheInterface $cache)
    {
    }

    public function forPeriod(string $from, string $to): array
    {
        if (substr($from, 0, 7) !== substr($to, 0, 7))
        {
            return ['days' => 21, 'source' => 'multiple_months'];
        }
        return $this->cache->get('working_days_'.substr($from, 0, 7), function (ItemInterface $item) use ($from): array
        {
            $item->expiresAfter(86400);
            try
            {
                $settings = $this->bitrix->calendarSettings();
                $weekdays = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];
                $week = $settings['week_holidays'] ?? null;
                if (!is_array($week) || !array_is_list($week) || array_filter($week, static fn ($value): bool => !is_string($value) || !in_array($value, $weekdays, true)) !== [])
                {
                    throw new BitrixFailure('INVALID_CALENDAR');
                }
                $exceptions = [];
                foreach (['year_holidays', 'year_workdays'] as $field)
                {
                    $value = $settings[$field] ?? ($field === 'year_workdays' ? '' : null);
                    if (!is_string($value))
                    {
                        throw new BitrixFailure('INVALID_CALENDAR');
                    }
                    $exceptions[$field] = [];
                    foreach ($value === '' ? [] : explode(',', $value) as $date)
                    {
                        if (!preg_match('/^(\d{1,2})\.(\d{1,2})$/D', trim($date), $parts) || !checkdate((int) $parts[2], (int) $parts[1], (int) substr($from, 0, 4)))
                        {
                            throw new BitrixFailure('INVALID_CALENDAR');
                        }
                        $exceptions[$field][] = (int) $parts[1].'.'.(int) $parts[2];
                    }
                }
                $day = new \DateTimeImmutable(substr($from, 0, 7).'-01', new \DateTimeZone('UTC'));
                $end = $day->modify('first day of next month');
                $count = 0;
                for (; $day < $end; $day = $day->modify('+1 day'))
                {
                    $date = $day->format('j').'.'.$day->format('n');
                    if (in_array($date, $exceptions['year_workdays'], true) || (!in_array($weekdays[(int) $day->format('N') - 1], $week, true) && !in_array($date, $exceptions['year_holidays'], true)))
                    {
                        ++$count;
                    }
                }
                return ['days' => $count, 'source' => 'calendar'];
            }
            catch (BitrixFailure)
            {
                $item->expiresAfter(300);
                return ['days' => 21, 'source' => 'fallback'];
            }
        });
    }
}
