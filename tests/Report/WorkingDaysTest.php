<?php

namespace App\Tests\Report;

use App\Bitrix\BitrixClient;
use App\Report\WorkingDays;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WorkingDaysTest extends TestCase
{
    public function testWholeMonthWithHolidayAndWorkingSaturdayIsCached(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function ($method, $url) use (&$calls): MockResponse
        {
            ++$calls;
            self::assertStringEndsWith('/calendar.settings.get', $url);
            return new MockResponse('{"result":{"week_holidays":["SA","SU"],"year_holidays":"1.09","year_workdays":"5.09"}}');
        });
        $days = new WorkingDays(new BitrixClient($http, 'https://example.bitrix24.ru', '1', 'secret'), new ArrayAdapter());
        self::assertSame(['days' => 22, 'source' => 'calendar'], $days->forPeriod('2026-09-02', '2026-09-07'));
        self::assertSame(['days' => 22, 'source' => 'calendar'], $days->forPeriod('2026-09-10', '2026-09-12'));
        self::assertSame(1, $calls);
    }

    public function testMultipleMonthsDoNotRequestCalendar(): void
    {
        $http = new MockHttpClient(function (): never { self::fail('Calendar must not be requested'); });
        $days = new WorkingDays(new BitrixClient($http, 'https://example.bitrix24.ru', '1', 'secret'), new ArrayAdapter());
        self::assertSame(['days' => 21, 'source' => 'multiple_months'], $days->forPeriod('2026-09-30', '2026-10-01'));
    }

    public function testMalformedCalendarAndDeniedAccessFallBack(): void
    {
        foreach (['{"result":{"week_holidays":["SA"],"year_holidays":"31.02"}}', '{"result":{"week_holidays":[{}],"year_holidays":""}}', '{"error":"ACCESS_DENIED"}'] as $body)
        {
            $days = new WorkingDays(new BitrixClient(new MockHttpClient(new MockResponse($body)), 'https://example.bitrix24.ru', '1', 'secret'), new ArrayAdapter());
            self::assertSame(['days' => 21, 'source' => 'fallback'], $days->forPeriod('2026-09-01', '2026-09-30'));
        }
    }
}
