<?php

namespace App\Tests\Report;

use App\Bitrix\BitrixClient;
use App\Report\ReportCalculator;
use App\Report\ReportPeriod;
use App\Report\TaskReportCollector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TaskReportCollectorTest extends TestCase
{
    public function testAccompliceRoleUsesSelectedListWithoutParticipantRequest(): void
    {
        $http = new MockHttpClient([new MockResponse('{"result":[{"ID":1,"TASK_ID":42,"USER_ID":7,"SECONDS":23,"CREATED_DATE":"2026-09-10T10:00:00Z"}]}')]);
        $client = new BitrixClient($http, 'https://example.bitrix24.ru', '1', 'secret');
        $row = (new TaskReportCollector($client, new ReportCalculator()))->collectTask(['id' => 42, 'title' => 'Task', 'responsibleId' => 8, 'status' => '5', 'accomplices' => [7]], 7, new ReportPeriod('2026-09-01', '2026-09-30', 'UTC'));
        self::assertSame('Соисполнитель', $row['role']);
        self::assertSame(23, $row['seconds']);
        self::assertSame(1, $http->getRequestsCount());
    }

    public function testExecutorRoleUsesKnownResponsibleWithoutUnnecessaryParticipantRequest(): void
    {
        $http = new MockHttpClient([new MockResponse('{"result":[{"ID":1,"TASK_ID":42,"USER_ID":7,"SECONDS":23,"CREATED_DATE":"2026-09-10T10:00:00Z"}]}')]);
        $client = new BitrixClient($http, 'https://example.bitrix24.ru', '1', 'secret');
        $row = (new TaskReportCollector($client, new ReportCalculator()))->collectTask(['id' => 42, 'title' => 'Task', 'responsibleId' => 7, 'status' => 'completed'], 7, new ReportPeriod('2026-09-01', '2026-09-30', 'UTC'));
        self::assertSame(23, $row['seconds']);
        self::assertSame('Исполнитель', $row['role']);
        self::assertSame(1, $http->getRequestsCount());
    }
}
