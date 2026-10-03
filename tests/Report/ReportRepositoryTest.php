<?php

namespace App\Tests\Report;

use App\Kernel;
use App\Report\ReportPeriod;
use App\Report\ReportRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReportRepositoryTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testReplacingTaskAfterRedeliveryDoesNotDuplicateTime(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $connection->beginTransaction();
        try
        {
            $repository = new ReportRepository($connection);
            $id = $repository->create(['id' => 7, 'name' => 'Employee'], new ReportPeriod('2026-09-01', '2026-09-30', 'UTC'));
            $row = ['id' => 42, 'title' => 'Task', 'seconds' => 23, 'entries' => [], 'role' => 'Без роли'];
            $repository->saveTask($id, $row);
            $repository->saveTask($id, $row);
            self::assertCount(1, $repository->rows($id));
            self::assertSame(23, $repository->rows($id)[0]['seconds']);
            self::assertSame('queued', $repository->find($id)['status']);
        }
        finally
        {
            $connection->rollBack();
        }
    }

    public function testPurgeCascadesPartialRowsAndErrorsButKeepsActiveJobs(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $connection->beginTransaction();
        try
        {
            $reports = new ReportRepository($connection);
            $period = new ReportPeriod('2026-09-01', '2026-09-30', 'UTC');
            $old = $reports->create(['id' => 7, 'name' => 'Employee'], $period);
            $active = $reports->create(['id' => 7, 'name' => 'Employee'], $period);
            $reports->update($old, ['status' => 'complete_with_errors', 'created_at' => '2000-01-01T00:00:00Z']);
            $reports->update($active, ['status' => 'waiting', 'created_at' => '2000-01-01T00:00:00Z']);
            $reports->saveTask($old, ['id' => 42, 'seconds' => 23]);
            $reports->saveError($old, ['id' => 42, 'title' => 'Task'], 'participants', 'ACCESS_DENIED', 1, 'exhausted', null);
            self::assertSame(1, $reports->purgeExpired());
            self::assertNull($reports->find($old));
            self::assertSame([], $reports->rows($old));
            self::assertSame([], $reports->errors($old));
            self::assertSame('waiting', $reports->find($active)['status']);
        }
        finally
        {
            $connection->rollBack();
        }
    }
}
