<?php

namespace App\Tests\Configuration;

use App\Kernel;
use App\Report\ReportPeriod;
use App\Report\ReportRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261002000100;
use DoctrineMigrations\Version20261002000200;
use DoctrineMigrations\Version20261002000300;
use DoctrineMigrations\Version20261002000400;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SelectionMigrationTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testMigrationInvalidatesActiveSelectionAndKeepsStoredResults(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $connection->beginTransaction();
        try
        {
            $schemaName = 'selection_test_'.bin2hex(random_bytes(6));
            $connection->executeStatement('CREATE SCHEMA '.$schemaName);
            $connection->executeStatement('SET LOCAL search_path TO '.$schemaName);
            foreach ([Version20261002000100::class, Version20261002000200::class, Version20261002000300::class] as $class)
            {
                $migration = new $class($connection, new NullLogger());
                $migration->up(new Schema());
                foreach ($migration->getSql() as $query)
                {
                    $connection->executeStatement($query->getStatement());
                }
            }
            $reports = new ReportRepository($connection);
            $period = new ReportPeriod('2026-09-01', '2026-09-30', 'UTC');
            $active = $reports->create(['id' => 7, 'name' => 'Employee'], $period);
            $complete = $reports->create(['id' => 7, 'name' => 'Employee'], $period);
            $reports->update($active, ['status' => 'waiting', 'retry_at' => 2000000000]);
            $reports->update($complete, ['status' => 'complete']);
            $reports->saveTask($active, ['id' => 42, 'seconds' => 23]);
            $reports->saveError($active, ['id' => 42, 'title' => 'Task'], 'participants', 'HTTP_503', 1, 'pending', 2000000000);
            $migration = new Version20261002000400($connection, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query)
            {
                $connection->executeStatement($query->getStatement());
            }
            self::assertSame('failed', $reports->find($active)['status']);
            self::assertSame('SELECTION_CHANGED', $reports->find($active)['error_code']);
            self::assertNull($reports->find($active)['retry_at']);
            self::assertNull($reports->find($active)['total_tasks']);
            self::assertSame(23, $reports->rows($active)[0]['seconds']);
            self::assertSame('exhausted', $reports->errors($active)[0]['state']);
            self::assertSame('complete', $reports->find($complete)['status']);
            self::assertNull($connection->fetchOne("SELECT to_regclass('messenger_messages')"));
            $reports->update($active, ['total_tasks' => 0]);
            self::assertSame(0, $reports->find($active)['total_tasks']);
            try
            {
                $reports->update($active, ['total_tasks' => -1]);
                self::fail('Negative selection totals must be rejected.');
            }
            catch (\Doctrine\DBAL\Exception\DriverException $exception)
            {
                self::assertSame('23514', $exception->getSQLState());
            }
        }
        finally
        {
            $connection->rollBack();
        }
    }
}
