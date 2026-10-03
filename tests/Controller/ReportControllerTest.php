<?php

namespace App\Tests\Controller;

use App\Kernel;
use App\Tests\UiFixtures;
use App\Bitrix\BitrixClient;
use App\Report\EmployeeDirectory;
use App\Report\ReportRepository;
use App\Report\CsvExporter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ReportControllerTest extends WebTestCase
{
    public function testAjaxDeleteValidatesCsrfAndLockAndCascadesOnlyChosenReport(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();
        $browser->request('GET', '/login');
        $browser->submitForm('Войти', ['_username' => 'report', '_password' => 'test-password']);
        self::getContainer()->set(EmployeeDirectory::class, new EmployeeDirectory(new BitrixClient(new MockHttpClient(new MockResponse('{"result":[]}')), 'https://example.bitrix24.ru', '1', 'secret'), new ArrayAdapter()));
        $connection = self::getContainer()->get(Connection::class);
        $connection->beginTransaction();
        try
        {
            $repository = new ReportRepository($connection);
            $period = new \App\Report\ReportPeriod('2026-10-01', '2026-10-30', 'UTC');
            $id = $repository->create(['id' => 7, 'name' => 'Delete target'], $period);
            $keep = $repository->create(['id' => 7, 'name' => 'Keep'], $period);
            $repository->saveTask($id, ['id' => 2, 'seconds' => 1800]);
            $repository->saveError($id, ['id' => 2, 'title' => 'Task'], 'time_entries', 'HTTP_503', 1, 'pending', null);
            $browser->request('POST', '/reports/'.$id.'/delete', ['_token' => 'forged'], server: ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseStatusCodeSame(403);
            self::assertNotNull($repository->find($id));
            $browser->request('GET', '/');
            $token = $browser->getCrawler()->filter('form[action="/reports/'.$id.'/delete"] input[name="_token"]')->attr('value');
            $other = DriverManager::getConnection($connection->getParams());
            try
            {
                $other->executeQuery('SELECT pg_advisory_lock(240024, 1)');
                $browser->request('POST', '/reports/'.$id.'/delete', ['_token' => $token], server: ['HTTP_ACCEPT' => 'application/json']);
                self::assertResponseStatusCodeSame(409);
                self::assertNotNull($repository->find($id));
            }
            finally
            {
                $other->close();
            }
            $browser->request('POST', '/reports/'.$id.'/delete', ['_token' => $token], server: ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseIsSuccessful();
            self::assertNull($repository->find($id));
            self::assertNotNull($repository->find($keep));
            self::assertSame([], $repository->rows($id));
            self::assertSame([], $repository->errors($id));
            $response = json_decode($browser->getResponse()->getContent(), true);
            self::assertNotContains($id, array_column($response['runs'], 'id'));
            self::assertArrayHasKey('deleteCsrf', $response['runs'][0]);
            $browser->request('POST', '/reports/'.$id.'/delete', ['_token' => $token], server: ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseIsSuccessful();
            self::assertNotNull($repository->find($keep));
        }
        finally
        {
            $connection->rollBack();
        }
    }

    public function testCalendarEndpointRequiresLoginAndReturnsOnlyNorm(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();
        $browser->request('GET', '/reports/cccccccccccccccccccccccccccccccc/workdays');
        self::assertResponseRedirects('/login');
        $browser->request('GET', '/login');
        $browser->submitForm('Войти', ['_username' => 'report', '_password' => 'test-password']);
        $days = new \App\Report\WorkingDays(new BitrixClient(new MockHttpClient(new MockResponse('{"result":{"week_holidays":["SA","SU"],"year_holidays":""}}')), 'https://example.bitrix24.ru', '1', 'secret'), new ArrayAdapter());
        self::getContainer()->set(\App\Report\WorkingDays::class, $days);
        $connection = self::getContainer()->get(Connection::class);
        $connection->beginTransaction();
        try
        {
            $id = (new ReportRepository($connection))->create(['id' => 7, 'name' => 'Calendar'], new \App\Report\ReportPeriod('2026-09-01', '2026-09-07', 'UTC'));
            $browser->request('GET', '/reports/'.$id.'/workdays');
            self::assertResponseIsSuccessful();
            self::assertSame(['days' => 22, 'source' => 'calendar'], json_decode($browser->getResponse()->getContent(), true));
            self::assertStringContainsString('no-store', $browser->getResponse()->headers->get('Cache-Control'));
        }
        finally
        {
            $connection->rollBack();
        }
    }

    public function testImmediateCollectionTargetsNewReportAndRejectsInvalidSubmission(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();
        $browser->catchExceptions(false);
        $browser->request('GET', '/login');
        $browser->submitForm('Войти', ['_username' => 'report', '_password' => 'test-password']);
        $http = new MockHttpClient([new MockResponse('{"result":{"tasks":[]},"total":0}'), new MockResponse('{"result":{"tasks":[]},"total":0}'), static function (): never
        {
            throw new \RuntimeException('secret unexpected failure');
        }]);
        self::getContainer()->set(BitrixClient::class, new BitrixClient($http, 'https://example.bitrix24.ru', '1', 'secret'));
        self::getContainer()->set(EmployeeDirectory::class, new EmployeeDirectory(new BitrixClient(new MockHttpClient(new MockResponse('{"result":[{"ID":"7","NAME":"Employee"}]}')), 'https://example.bitrix24.ru', '1', 'secret'), new ArrayAdapter()));
        $connection = self::getContainer()->get(Connection::class);
        $connection->beginTransaction();
        try
        {
            $repository = new ReportRepository($connection);
            $otherId = $repository->create(['id' => 7, 'name' => 'Other'], new \App\Report\ReportPeriod('2026-09-01', '2026-09-30', 'UTC'));
            $browser->request('GET', '/');
            $token = $browser->getCrawler()->filter('input[name="report_request[_token]"]')->attr('value');
            $data = ['employeeId' => '7', 'from' => '2026-09-01', 'to' => '2026-09-30', '_token' => $token, 'collectNow' => 'bogus'];
            $before = (int) $connection->fetchOne('SELECT COUNT(*) FROM report_runs');
            $browser->catchExceptions(true);
            $browser->request('POST', '/reports', ['report_request' => array_replace($data, ['collectNow' => '1', '_token' => 'forged'])]);
            self::assertResponseStatusCodeSame(403);
            self::assertSame($before, (int) $connection->fetchOne('SELECT COUNT(*) FROM report_runs'));
            self::assertSame(0, $http->getRequestsCount());
            $browser->catchExceptions(false);
            $browser->request('POST', '/reports', ['report_request' => $data]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame($before, (int) $connection->fetchOne('SELECT COUNT(*) FROM report_runs'));
            self::assertSame(0, $http->getRequestsCount());
            $data['collectNow'] = '1';
            $data['from'] = '2026-10-01';
            $browser->request('POST', '/reports', ['report_request' => $data]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame($before, (int) $connection->fetchOne('SELECT COUNT(*) FROM report_runs'));
            self::assertSame(0, $http->getRequestsCount());
            $data['from'] = '2026-09-01';
            $browser->request('POST', '/reports', ['report_request' => $data]);
            self::assertResponseRedirects();
            $id = basename($browser->getResponse()->headers->get('Location'));
            self::assertSame('complete', $repository->find($id)['status']);
            self::assertSame('queued', $repository->find($otherId)['status']);
            self::assertSame(2, $http->getRequestsCount());
            $other = DriverManager::getConnection($connection->getParams());
            try
            {
                $other->executeQuery('SELECT pg_advisory_lock(240024, 1)');
                $browser->request('POST', '/reports', ['report_request' => $data]);
                self::assertResponseRedirects();
                $busyId = basename($browser->getResponse()->headers->get('Location'));
                self::assertSame('queued', $repository->find($busyId)['status']);
                self::assertSame(2, $http->getRequestsCount());
            }
            finally
            {
                $other->close();
            }
            $browser->request('POST', '/reports', ['report_request' => $data]);
            self::assertResponseRedirects();
            $failedLocation = $browser->getResponse()->headers->get('Location');
            self::assertSame('queued', $repository->find(basename($failedLocation))['status']);
            $browser->request('GET', $failedLocation);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('COLLECTION_FAILED', $browser->getResponse()->getContent());
            self::assertStringNotContainsString('secret unexpected failure', $browser->getResponse()->getContent());
        }
        finally
        {
            $connection->rollBack();
        }
    }

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testReportsRequireLogin(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/');
        self::assertResponseRedirects('/login');
        $browser->request('GET', '/reports/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/progress');
        self::assertResponseRedirects('/login');
    }

    public function testCsvIncludesSafeMetadataAndAllErrorStates(): void
    {
        $csv = (new CsvExporter())->export([], ['status' => 'complete_with_errors', 'employee_name' => '=Employee', 'date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'timezone' => 'UTC'], [
            ['task_id' => 42, 'task_title' => '=SUM(1)', 'stage' => 'participants', 'error_code' => 'ACCESS_DENIED', 'attempts' => 5, 'state' => 'exhausted', 'retry_at' => null],
            ['task_id' => null, 'task_title' => null, 'stage' => 'list', 'error_code' => 'TIMEOUT', 'attempts' => 1, 'state' => 'resolved', 'retry_at' => null],
        ]);
        self::assertStringContainsString('Неполный', $csv);
        self::assertStringContainsString("'=Employee", $csv);
        self::assertStringContainsString("'=SUM(1)", $csv);
        self::assertStringContainsString('participants;ACCESS_DENIED;5;exhausted', $csv);
        self::assertStringContainsString('list;TIMEOUT;1;resolved', $csv);
    }

    public function testLoginPageHasCsrfAndNoSecretValues(): void
    {
        $browser = self::createClient();
        $browser->catchExceptions(false);
        $browser->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="_csrf_token"]');
        self::assertSelectorExists('script#app-data[type="application/json"]');
        self::assertStringNotContainsString('test-password', $browser->getResponse()->getContent());
        $payload = json_decode($browser->getCrawler()->filter('#app-data')->text(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('login', $payload['pageData']['page']);
        self::assertArrayNotHasKey('password', $payload['pageData']);
        UiFixtures::save('login', $browser->getResponse()->getContent());
    }

    public function testLoginAndRejectForgedReportSubmission(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/login');
        $browser->submitForm('Войти', ['_username' => 'report', '_password' => 'test-password']);
        self::assertResponseRedirects();
        $browser->request('POST', '/reports', ['employeeId' => 7, 'from' => '2026-09-01', 'to' => '2026-09-30']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testCreateProgressCompletedHtmlAndCsv(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();
        $browser->catchExceptions(false);
        $browser->request('GET', '/login');
        $browser->submitForm('Войти', ['_username' => 'report', '_password' => 'test-password']);
        $directory = new EmployeeDirectory(new BitrixClient(new MockHttpClient(new MockResponse('{"result":[{"ID":"7","NAME":"Employee"}]}')), 'https://example.bitrix24.ru', '1', 'secret'), new ArrayAdapter());
        self::getContainer()->set(EmployeeDirectory::class, $directory);
        $connection = self::getContainer()->get(Connection::class);
        $connection->beginTransaction();
        try
        {
            $browser->request('GET', '/');
            self::assertResponseIsSuccessful();
            UiFixtures::save('index', $browser->getResponse()->getContent());
            $browser->submitForm('Сформировать отчёт', ['report_request[employeeId]' => '7', 'report_request[from]' => '2026-09-01', 'report_request[to]' => '2026-09-30']);
            self::assertResponseRedirects();
            $location = $browser->getResponse()->headers->get('Location');
            $id = basename($location);
            $repository = new ReportRepository($connection);
            self::assertSame('queued', $repository->find($id)['status']);
            $browser->request('GET', $location.'/csv');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('Неполный', $browser->getResponse()->getContent());
            $repository->saveTask($id, ['id' => 42, 'title' => '<script>alert(1)</script>', 'status' => 'completed', 'responsibleId' => 8, 'role' => 'Без роли', 'seconds' => 3623, 'entries' => [['CREATED_DATE' => '2026-09-10T10:00:00Z', 'SECONDS' => 3623, 'COMMENT_TEXT' => '<img src=x>']]]);
            $repository->update($id, ['status' => 'waiting', 'total_tasks' => 12, 'pending_tasks' => [['id' => 999, 'private' => 'hidden']], 'error_code' => 'QUERY_LIMIT_EXCEEDED', 'retry_at' => 1790942400, 'timezone' => 'UTC']);
            $browser->request('GET', $location);
            self::assertSelectorTextContains('main', 'QUERY_LIMIT_EXCEEDED');
            self::assertSelectorTextContains('main', '02.10.2026 12:00:00 UTC');
            $connection->insert('report_errors', ['run_id' => $id, 'task_id' => 42, 'task_title' => '=SUM(1)', 'stage' => 'participants', 'error_code' => 'ACCESS_DENIED', 'attempts' => 5, 'state' => 'exhausted']);
            $browser->request('GET', $location);
            self::assertSelectorTextContains('tfoot', '01:00');
            self::assertSelectorNotExists('meta[http-equiv="refresh"]');
            self::assertSelectorTextContains('main', 'Получение участников (participants)');
            self::assertSelectorTextContains('main', 'Повторы завершены (exhausted)');
            UiFixtures::save('partial', $browser->getResponse()->getContent());
            $browser->request('GET', $location.'/progress');
            self::assertResponseIsSuccessful();
            $progress = json_decode($browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(3623, $progress['rows'][0]['seconds']);
            self::assertSame('01:00', $progress['total']);
            self::assertSame(7, $progress['run']['employee_id']);
            self::assertSame(12, $progress['run']['total_tasks']);
            self::assertFalse($progress['complete']);
            self::assertSame('participants', $progress['errors'][0]['stage']);
            self::assertArrayNotHasKey('pending_tasks', $progress['run']);
            self::assertArrayNotHasKey('cursor_id', $progress['run']);
            self::assertStringContainsString('no-store', $browser->getResponse()->headers->get('Cache-Control'));
            $browser->request('GET', $location.'/csv');
            self::assertStringContainsString('exhausted', $browser->getResponse()->getContent());
            $repository->update($id, ['status' => 'complete_with_errors']);
            $browser->request('GET', $location.'/progress');
            self::assertTrue(json_decode($browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['complete']);
            $repository->update($id, ['status' => 'complete']);
            $browser->request('GET', $location);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('tfoot', '01:00');
            self::assertStringNotContainsString('<script>alert', $browser->getResponse()->getContent());
            self::assertStringNotContainsString('<img src=x>', $browser->getResponse()->getContent());
            $payload = json_decode($browser->getCrawler()->filter('#app-data')->text(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('<script>alert(1)</script>', $payload['pageData']['rows'][0]['title']);
            self::assertArrayNotHasKey('pending_tasks', $payload['pageData']['run']);
            UiFixtures::save('report', $browser->getResponse()->getContent());
            $browser->request('GET', $location.'/csv');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('01:00', $browser->getResponse()->getContent());
            $browser->catchExceptions(true);
            $browser->request('POST', $location.'/refresh');
            self::assertResponseStatusCodeSame(403);
            $browser->request('GET', '/');
            $indexPayload = json_decode($browser->getCrawler()->filter('#app-data')->text(), true, 512, JSON_THROW_ON_ERROR);
            self::assertNotEmpty($indexPayload['pageData']['runs']);
            foreach ($indexPayload['pageData']['runs'] as $run)
            {
                self::assertArrayNotHasKey('pending_tasks', $run);
            }
            $browser->submitForm('Сформировать отчёт', ['report_request[employeeId]' => '7', 'report_request[from]' => '2026-10-01', 'report_request[to]' => '2026-09-30']);
            self::assertResponseStatusCodeSame(422);
            UiFixtures::save('invalid', $browser->getResponse()->getContent());
        }
        finally
        {
            $connection->rollBack();
        }
    }
}
