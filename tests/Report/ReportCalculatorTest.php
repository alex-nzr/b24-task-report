<?php

namespace App\Tests\Report;

use App\Report\CsvExporter;
use App\Report\ReportCalculator;
use App\Report\ReportPeriod;
use PHPUnit\Framework\TestCase;

final class ReportCalculatorTest extends TestCase
{
    public function testMissingUserIdCannotSilentlyDisappearFromReport(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new ReportCalculator())->summarize([['ID' => 1, 'TASK_ID' => 42, 'SECONDS' => 23, 'CREATED_DATE' => '2026-09-10T10:00:00Z']], 7, new ReportPeriod('2026-09-01', '2026-09-30', 'UTC'));
    }

    public function testRelativeDateCannotBeAcceptedAsPortalTimestamp(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new ReportCalculator())->summarize([['ID' => 1, 'TASK_ID' => 42, 'USER_ID' => 7, 'SECONDS' => 23, 'CREATED_DATE' => 'yesterday']], 7, new ReportPeriod('2026-09-01', '2026-09-30', 'UTC'));
    }

    public function testCountsSecondsOnceForEmployeeWithinInclusiveDates(): void
    {
        $period = new ReportPeriod('2026-09-01', '2026-09-30', 'Asia/Yekaterinburg');
        $first = ['ID' => '1', 'TASK_ID' => '10', 'USER_ID' => '7', 'SECONDS' => '23', 'MINUTES' => '0', 'CREATED_DATE' => '2026-09-01T00:00:00+05:00'];
        $entries = [$first, $first,
            ['ID' => '2', 'TASK_ID' => '10', 'USER_ID' => '7', 'SECONDS' => '3600', 'CREATED_DATE' => '2026-09-30T23:59:59+05:00'],
            ['ID' => '3', 'TASK_ID' => '10', 'USER_ID' => '8', 'SECONDS' => '100', 'CREATED_DATE' => '2026-09-20T00:00:00+05:00'],
            ['ID' => '4', 'TASK_ID' => '10', 'USER_ID' => '7', 'SECONDS' => '100', 'CREATED_DATE' => '2026-10-01T00:00:00+05:00']];
        $result = (new ReportCalculator())->summarize($entries, 7, $period);
        self::assertSame(3623, $result['seconds']);
        self::assertCount(2, $result['entries']);
        self::assertSame('01:00', ReportCalculator::formatSeconds($result['seconds']));
        self::assertSame('25:00', ReportCalculator::formatSeconds(90000));
    }

    public function testInvalidCalendarDateIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ReportPeriod('2026-02-30', '2026-03-01', 'UTC');
    }

    public function testRolesAreInformationalAndHaveDefinedPrecedence(): void
    {
        $calculator = new ReportCalculator();
        self::assertSame('Исполнитель', $calculator->role(['responsible' => ['id' => 7], 'accomplices' => [['id' => 7]], 'auditors' => []], 7));
        self::assertSame('Соисполнитель', $calculator->role(['responsible' => ['id' => 8], 'accomplices' => [['id' => '7']], 'auditors' => [['id' => 7]]], 7));
        self::assertSame('Наблюдатель', $calculator->role(['responsible' => ['id' => 8], 'accomplices' => [], 'auditors' => [['id' => 7]]], 7));
        self::assertSame('Без роли', $calculator->role(['responsible' => ['id' => 8], 'accomplices' => [], 'auditors' => []], 7));
    }

    public function testMissingRolesAreNotReportedAsNoRole(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new ReportCalculator())->role(['responsible' => ['id' => 8]], 7);
    }

    public function testCsvNeutralizesFormulaAndPreservesTotals(): void
    {
        $csv = (new CsvExporter())->export([['id' => 10, 'title' => ' =1+1', 'status' => 'completed', 'role' => 'Без роли', 'seconds' => 23, 'entries' => []]]);
        self::assertStringContainsString("' =1+1", $csv);
        self::assertStringContainsString(';00:00;23;', $csv);
    }
}
