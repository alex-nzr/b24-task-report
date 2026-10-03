<?php

namespace App\Tests\Report;

use App\Bitrix\BitrixClient;
use App\Command\ReportCollectCommand;
use App\Kernel;
use App\Report\ReportCalculator;
use App\Report\ReportPeriod;
use App\Report\ReportProcessor;
use App\Report\ReportRepository;
use App\Report\ReportRunner;
use App\Report\TaskReportCollector;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CommandCollectionTest extends KernelTestCase
{
    public function testSelectionStoresInitialDenominatorAndListRole(): void
    {
        $this->processor([
            new MockResponse('{"result":{"tasks":[{"id":"42","title":"Task","status":"5","responsibleId":"8","accomplices":["7"]}]},"total":9}'),
            new MockResponse('{"result":{"tasks":[{"id":"42","title":"Task","status":"5","responsibleId":"8","accomplices":["7"]}]},"total":9}'),
            $this->timeResponse(),
        ])->step($this->id);
        $run = $this->reports->find($this->id);
        self::assertSame(9, $run['total_tasks']);
        self::assertSame(42, $run['max_id']);
        self::assertSame(1, $run['checked_tasks']);
        self::assertSame('Соисполнитель', $this->reports->rows($this->id)[0]['role']);
    }

    public function testTargetedRunnerLeavesOtherQueuedReportUntouched(): void
    {
        $otherId = $this->reports->create(['id' => 8, 'name' => 'Other'], new ReportPeriod('2026-09-01', '2026-09-30', 'UTC'));
        $runner = new ReportRunner($this->connection, $this->reports, $this->processor([new MockResponse('{"result":{"tasks":[]},"total":0}'), new MockResponse('{"result":{"tasks":[]},"total":0}') ]));
        self::assertSame(['busy' => false, 'steps' => 1], $runner->run(45, $this->id));
        self::assertSame('complete', $this->reports->find($this->id)['status']);
        self::assertSame('queued', $this->reports->find($otherId)['status']);
    }

    private Connection $connection;
    private ReportRepository $reports;
    private string $id;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->reports = new ReportRepository($this->connection);
        $this->id = $this->reports->create(['id' => 7, 'name' => 'Employee'], new ReportPeriod('2026-09-01', '2026-09-30', 'UTC'));
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    private function processor(array $responses): ReportProcessor
    {
        $client = new BitrixClient(new MockHttpClient($responses), 'https://example.bitrix24.ru', '1', 'secret');
        return new ReportProcessor($this->connection, $this->reports, $client, new TaskReportCollector($client, new ReportCalculator()));
    }

    private function prepareTask(): void
    {
        $this->reports->update($this->id, ['max_id' => 42, 'pending_tasks' => [['id' => 42, 'title' => 'Task', 'status' => 'completed', 'responsibleId' => 8]]]);
    }

    private function timeResponse(): MockResponse
    {
        return new MockResponse('{"result":[{"ID":1,"TASK_ID":42,"USER_ID":7,"SECONDS":23,"CREATED_DATE":"2026-09-10T10:00:00Z"}]}');
    }

    public function testRoleFailureKeepsKnownTimeAndErrorThenContinues(): void
    {
        $this->prepareTask();
        $processor = $this->processor([$this->timeResponse(), new MockResponse('{"error":"ACCESS_DENIED"}', ['http_code' => 403]), new MockResponse('{"result":{"tasks":[]},"total":0}')]);
        $processor->step($this->id);
        self::assertSame(23, $this->reports->rows($this->id)[0]['seconds']);
        self::assertSame('Не удалось определить', $this->reports->rows($this->id)[0]['role']);
        self::assertSame('participants', $this->reports->errors($this->id)[0]['stage']);
        self::assertSame('exhausted', $this->reports->errors($this->id)[0]['state']);
        $processor->step($this->id);
        self::assertSame('complete_with_errors', $this->reports->find($this->id)['status']);
    }

    public function testTimeFailureIsNotZeroOrAnEmptySuccessfulReport(): void
    {
        $this->prepareTask();
        $processor = $this->processor([new MockResponse('{"error":"ACCESS_DENIED"}', ['http_code' => 403]), new MockResponse('{"result":{"tasks":[]},"total":0}')]);
        $processor->step($this->id);
        self::assertSame([], $this->reports->rows($this->id));
        self::assertSame('time_entries', $this->reports->errors($this->id)[0]['stage']);
        $processor->step($this->id);
        self::assertSame('complete_with_errors', $this->reports->find($this->id)['status']);
    }

    public function testRetryResolvesErrorAndReplacesPartialRowWithoutDuplicates(): void
    {
        $this->prepareTask();
        $processor = $this->processor([$this->timeResponse(), new MockResponse('', ['http_code' => 503]), new MockResponse('{"result":{"item":{"responsible":{"id":8},"accomplices":[],"auditors":[]}}}'), new MockResponse('{"result":{"tasks":[]},"total":0}')]);
        $processor->step($this->id);
        self::assertSame('waiting', $this->reports->find($this->id)['status']);
        self::assertSame(0, $this->reports->find($this->id)['cursor_id']);
        self::assertSame('pending', $this->reports->errors($this->id)[0]['state']);
        $this->reports->update($this->id, ['retry_at' => 0]);
        $processor->step($this->id);
        $processor->step($this->id);
        self::assertCount(1, $this->reports->rows($this->id));
        self::assertSame(23, $this->reports->rows($this->id)[0]['seconds']);
        self::assertSame('Без роли', $this->reports->rows($this->id)[0]['role']);
        self::assertSame('resolved', $this->reports->errors($this->id)[0]['state']);
        self::assertSame('complete', $this->reports->find($this->id)['status']);
    }

    public function testFiveFailuresStopRetryingTaskButAllowNextTask(): void
    {
        $this->prepareTask();
        $responses = [];
        for ($i = 0; $i < 5; $i++)
        {
            $responses[] = new MockResponse('', ['http_code' => 503]);
        }
        $processor = $this->processor($responses);
        for ($i = 0; $i < 5; $i++)
        {
            $this->reports->update($this->id, ['retry_at' => 0]);
            $processor->step($this->id);
        }
        $run = $this->reports->find($this->id);
        self::assertSame(42, $run['cursor_id']);
        self::assertSame(1, $run['checked_tasks']);
        self::assertSame('exhausted', $this->reports->errors($this->id)[0]['state']);
        self::assertSame(5, $this->reports->errors($this->id)[0]['attempts']);
    }

    public function testListFailureRecordsUnknownTaskAndStopsIncompleteEnumeration(): void
    {
        $this->processor([new MockResponse('{"error":"ACCESS_DENIED"}', ['http_code' => 403])])->step($this->id);
        self::assertSame('failed', $this->reports->find($this->id)['status']);
        self::assertNull($this->reports->errors($this->id)[0]['task_id']);
        self::assertSame('task_list', $this->reports->errors($this->id)[0]['stage']);
    }

    public function testMalformedUpperBoundaryDoesNotProduceSuccessfulEmptyReport(): void
    {
        $this->processor([new MockResponse('{"result":{"tasks":[{"id":"invalid","title":"Task","responsibleId":"7","status":"5","accomplices":[]}]},"total":1}')])->step($this->id);
        self::assertSame('failed', $this->reports->find($this->id)['status']);
        self::assertSame('INVALID_TASK_PAGE', $this->reports->errors($this->id)[0]['error_code']);
    }

    public function testOperationLimitDoesNotDiscardTaskAfterFivePauses(): void
    {
        $this->prepareTask();
        $responses = [];
        for ($i = 0; $i < 6; $i++)
        {
            $responses[] = new MockResponse('{"error":"OPERATION_TIME_LIMIT"}', ['http_code' => 429]);
        }
        $processor = $this->processor($responses);
        for ($i = 0; $i < 6; $i++)
        {
            $this->reports->update($this->id, ['retry_at' => 0]);
            $processor->step($this->id);
        }
        self::assertSame('waiting', $this->reports->find($this->id)['status']);
        self::assertSame(0, $this->reports->find($this->id)['checked_tasks']);
        self::assertSame('pending', $this->reports->errors($this->id)[0]['state']);
    }

    public function testCommandProcessesExistingJobWithoutQueue(): void
    {
        $this->prepareTask();
        $processor = $this->processor([$this->timeResponse(), new MockResponse('{"result":{"item":{"responsible":{"id":8},"accomplices":[],"auditors":[]}}}'), new MockResponse('{"result":{"tasks":[]},"total":0}')]);
        $tester = new CommandTester(new ReportCollectCommand(new ReportRunner($this->connection, $this->reports, $processor)));
        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('complete', $this->reports->find($this->id)['status']);
        self::assertSame(23, $this->reports->rows($this->id)[0]['seconds']);
    }

    public function testMethodLimitPausesDoNotConsumeOrdinaryFailureBudget(): void
    {
        $this->prepareTask();
        $responses = [];
        for ($i = 0; $i < 5; $i++)
        {
            $responses[] = new MockResponse('{"error":"OPERATION_TIME_LIMIT"}', ['http_code' => 429]);
        }
        $responses[] = new MockResponse('', ['http_code' => 503]);
        $processor = $this->processor($responses);
        for ($i = 0; $i < 6; $i++)
        {
            $this->reports->update($this->id, ['retry_at' => 0]);
            $processor->step($this->id);
        }
        self::assertSame('waiting', $this->reports->find($this->id)['status']);
        self::assertSame(1, $this->reports->find($this->id)['attempts']);
        self::assertSame('pending', $this->reports->errors($this->id)[0]['state']);
        self::assertSame(6, $this->reports->errors($this->id)[0]['attempts']);
    }

    public function testCommandDefaultsTo45AndReturnsImmediatelyWithoutReadyJobs(): void
    {
        $this->reports->update($this->id, ['status' => 'complete']);
        $command = new ReportCollectCommand(new ReportRunner($this->connection, $this->reports, $this->processor([])));
        self::assertSame(45, $command->getDefinition()->getOption('time-limit')->getDefault());
        $tester = new CommandTester($command);
        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('0', $tester->getDisplay());
        $tester->execute(['--time-limit' => '0']);
        self::assertSame(2, $tester->getStatusCode());
    }

    public function testCommandDoesNotCollectWhileAnotherConnectionHoldsLock(): void
    {
        $other = DriverManager::getConnection($this->connection->getParams());
        try
        {
            $other->executeQuery('SELECT pg_advisory_lock(240024, 1)');
            $tester = new CommandTester(new ReportCollectCommand(new ReportRunner($this->connection, $this->reports, $this->processor([]))));
            $tester->execute([]);
            self::assertSame(0, $tester->getStatusCode());
            self::assertSame('queued', $this->reports->find($this->id)['status']);
            self::assertStringContainsString('уже', $tester->getDisplay());
        }
        finally
        {
            $other->close();
        }
    }

    public function testSoftBudgetCompletesCurrentTaskButDoesNotStartAnother(): void
    {
        $this->reports->update($this->id, ['max_id' => 43, 'pending_tasks' => [
            ['id' => 42, 'title' => 'First', 'status' => 'completed', 'responsibleId' => 7],
            ['id' => 43, 'title' => 'Second', 'status' => 'completed', 'responsibleId' => 7],
        ]]);
        $http = new MockHttpClient(static function (): MockResponse
        {
            usleep(1100000);
            return new MockResponse('{"result":[{"ID":1,"TASK_ID":42,"USER_ID":7,"SECONDS":23,"CREATED_DATE":"2026-09-10T10:00:00Z"}]}');
        });
        $client = new BitrixClient($http, 'https://example.bitrix24.ru', '1', 'secret');
        $processor = new ReportProcessor($this->connection, $this->reports, $client, new TaskReportCollector($client, new ReportCalculator()));
        $tester = new CommandTester(new ReportCollectCommand(new ReportRunner($this->connection, $this->reports, $processor)));
        $tester->execute(['--time-limit' => '1']);
        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(1, $this->reports->find($this->id)['checked_tasks']);
        self::assertSame(43, $this->reports->find($this->id)['pending_tasks'][0]['id']);
        self::assertSame(23, $this->reports->rows($this->id)[0]['seconds']);
    }
}
