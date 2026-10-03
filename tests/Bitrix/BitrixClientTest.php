<?php

namespace App\Tests\Bitrix;

use App\Bitrix\BitrixClient;
use App\Bitrix\BitrixFailure;
use App\Report\ReportPeriod;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BitrixClientTest extends TestCase
{
    public function testSelectedTasksUseClassicScopedFilterAndNormalizeParticipants(): void
    {
        $request = null;
        $http = new MockHttpClient(function ($method, $url, $options) use (&$request): MockResponse
        {
            $request = [$url, json_decode($options['body'], true)];
            return new MockResponse('{"result":{"tasks":[{"id":"42","title":"Task","responsibleId":"8","status":"5","accomplices":["7","9"]}]},"total":12}');
        });
        $client = new BitrixClient($http, 'https://example.bitrix24.ru', '1', 'secret');
        $page = $client->selectedTasks(7, new ReportPeriod('2026-09-01', '2026-09-30', 'Asia/Yekaterinburg'), 10, 100);
        self::assertSame(['tasks' => [['id' => 42, 'title' => 'Task', 'responsibleId' => 8, 'status' => '5', 'accomplices' => [7, 9]]], 'total' => 12], $page);
        self::assertStringContainsString('/rest/1/secret/tasks.task.list', $request[0]);
        self::assertSame(['>ID' => 10, '<=ID' => 100, '>=ACTIVITY_DATE' => '2026-09-01T00:00:00+05:00', 'DOER' => 7], $request[1]['filter']);
        self::assertSame(['ID' => 'ASC'], $request[1]['order']);
    }

    public function testSelectedTasksRejectMissingTotalInsteadOfClaimingComplete(): void
    {
        $client = new BitrixClient(new MockHttpClient(new MockResponse('{"result":{"tasks":[]}}')), 'https://example.bitrix24.ru', '1', 'secret');
        $this->expectException(BitrixFailure::class);
        $this->expectExceptionMessage('INVALID_RESPONSE');
        $client->selectedTasks(7, new ReportPeriod('2026-09-01', '2026-09-30', 'UTC'), 0, null, true);
    }

    public function testSelectedTasksRejectOutOfCursorRangeAndUnsortedPage(): void
    {
        foreach ([
            '{"result":{"tasks":[{"id":42,"title":"Task","responsibleId":8,"status":"5","accomplices":[7]}]},"total":1}',
            '{"result":{"tasks":[{"id":44,"title":"Task","responsibleId":8,"status":"5","accomplices":[7]},{"id":43,"title":"Task","responsibleId":8,"status":"5","accomplices":[7]}]},"total":2}',
        ] as $body)
        {
            $client = new BitrixClient(new MockHttpClient(new MockResponse($body)), 'https://example.bitrix24.ru', '1', 'secret');
            try
            {
                $client->selectedTasks(7, new ReportPeriod('2026-09-01', '2026-09-30', 'UTC'), 42, 100);
                self::fail('Page must respect the cursor and ordering.');
            }
            catch (BitrixFailure $failure)
            {
                self::assertSame('INVALID_TASK_PAGE', $failure->errorCode);
            }
        }
    }

    public function testSelectedTasksRejectBooleanIdentifiersAndOversizedMetadata(): void
    {
        foreach ([
            '{"result":{"tasks":[{"id":true,"title":"Task","responsibleId":8,"status":"5","accomplices":[7]}]},"total":1}',
            '{"result":{"tasks":[{"id":42,"title":"Task","responsibleId":8,"status":"5","accomplices":[true]}]},"total":1}',
            '{"result":{"tasks":[{"id":42,"title":"Task","responsibleId":8,"status":"5","accomplices":[7]}]},"total":2147483648}',
        ] as $body)
        {
            $client = new BitrixClient(new MockHttpClient(new MockResponse($body)), 'https://example.bitrix24.ru', '1', 'secret');
            try
            {
                $client->selectedTasks(7, new ReportPeriod('2026-09-01', '2026-09-30', 'UTC'), 0, null);
                self::fail('Malformed numeric data must not be coerced into report identifiers.');
            }
            catch (BitrixFailure $failure)
            {
                self::assertContains($failure->errorCode, ['INVALID_TASK_PAGE', 'INVALID_RESPONSE']);
            }
        }
    }

    public function testEmptyRateLimitResponseRemainsRetryable(): void
    {
        $client = new BitrixClient(new MockHttpClient(new MockResponse('', ['http_code' => 429])), 'https://example.bitrix24.ru', '1', 'secret');
        try
        {
            $client->tasks(0, 100);
            self::fail('Limit must stop this request.');
        }
        catch (BitrixFailure $failure)
        {
            self::assertSame('HTTP_429', $failure->errorCode);
            self::assertNotNull($failure->retryAt);
        }
    }

    public function testMalformedSuccessfulResponseIsFatal(): void
    {
        $client = new BitrixClient(new MockHttpClient(new MockResponse('<html>not JSON</html>')), 'https://example.bitrix24.ru', '1', 'secret');
        try
        {
            $client->tasks(0, 100);
            self::fail('Malformed response must fail.');
        }
        catch (BitrixFailure $failure)
        {
            self::assertSame('INVALID_RESPONSE', $failure->errorCode);
            self::assertNull($failure->retryAt);
        }
    }

    public function testNonJsonServiceFailureIsRetryableAndDoesNotLeakBody(): void
    {
        $client = new BitrixClient(new MockHttpClient(new MockResponse('<html>secret gateway</html>', ['http_code' => 503])), 'https://example.bitrix24.ru', '1', 'secret');
        try
        {
            $client->tasks(0, 100);
            self::fail('Service failure must stop this request.');
        }
        catch (BitrixFailure $failure)
        {
            self::assertSame('HTTP_503', $failure->errorCode);
            self::assertNotNull($failure->retryAt);
            self::assertStringNotContainsString('secret', $failure->getMessage());
        }
    }

    public function testRepeatedFullTimePageFailsInsteadOfLoopingForever(): void
    {
        $items = [];
        for ($id = 1; $id <= 50; $id++)
        {
            $items[] = ['ID' => $id, 'TASK_ID' => 42];
        }
        $body = json_encode(['result' => $items]);
        $client = new BitrixClient(new MockHttpClient([new MockResponse($body), new MockResponse($body)]), 'https://example.bitrix24.ru', '1', 'secret');
        $this->expectException(BitrixFailure::class);
        $this->expectExceptionMessage('INVALID_PAGINATION');
        $client->timeEntries(42, 7, new ReportPeriod('2026-09-01', '2026-09-30', 'UTC'));
    }

    public function testUsesV3ForTasksAndPositionalClassicRequestForTime(): void
    {
        $requests = [];
        $http = new MockHttpClient(function ($method, $url, $options) use (&$requests)
        {
            $requests[] = [$url, json_decode($options['body'], true)];
            return new MockResponse(count($requests) === 1 ? '{"result":{"items":[{"id":42,"title":"Task"}]}}' : '{"result":[]}');
        });
        $client = new BitrixClient($http, 'https://example.bitrix24.ru', '1', 'secret');
        self::assertSame(42, $client->tasks(0, 100)[0]['id']);
        self::assertSame([], $client->timeEntries(42, 7, new ReportPeriod('2026-09-01', '2026-09-30', 'Asia/Yekaterinburg')));
        self::assertStringContainsString('/rest/api/1/secret/tasks.task.list', $requests[0][0]);
        self::assertSame([['id', '>', 0], ['id', '<=', 100]], $requests[0][1]['filter']);
        self::assertStringContainsString('/rest/1/secret/task.elapseditem.getlist', $requests[1][0]);
        self::assertSame(42, $requests[1][1][0]);
        self::assertSame(7, $requests[1][1][2]['USER_ID']);
        self::assertSame('2026-10-01T00:00:00+05:00', $requests[1][1][2]['<CREATED_DATE']);
    }

    public function testOperationLimitReturnsSafeResetWithoutLeakingToken(): void
    {
        $http = new MockHttpClient([
            new MockResponse('{"result":{"items":[]},"time":{"operating_reset_at":2000000000}}'),
            new MockResponse('{"error":"OPERATION_TIME_LIMIT","error_description":"secret"}', ['http_code' => 429]),
        ]);
        $client = new BitrixClient($http, 'https://example.bitrix24.ru', '1', 'secret');
        $client->tasks(0, 100);
        try
        {
            $client->tasks(0, 100);
            self::fail('Limit must stop collection.');
        }
        catch (BitrixFailure $failure)
        {
            self::assertSame(2000000002, $failure->retryAt);
            self::assertStringNotContainsString('secret', $failure->getMessage());
        }
    }

    public function testV3AccessFailureIsNotRetriedOrConvertedToEmptyResult(): void
    {
        $client = new BitrixClient(new MockHttpClient(new MockResponse('{"error":{"code":"BITRIX_REST_V3_EXCEPTION_ACCESSDENIEDEXCEPTION","message":"secret"}}', ['http_code' => 403])), 'https://example.bitrix24.ru', '1', 'secret');
        $this->expectException(BitrixFailure::class);
        $client->tasks(0, 100);
    }

    public function testTimePaginationLoadsPageAfterFiftyEntries(): void
    {
        $entries = [];
        for ($id = 1; $id <= 50; ++$id)
        {
            $entries[] = ['ID' => $id, 'TASK_ID' => 42, 'USER_ID' => 7, 'SECONDS' => 1];
        }
        $http = new MockHttpClient([new MockResponse(json_encode(['result' => $entries])), new MockResponse('{"result":[{"ID":51,"TASK_ID":42,"USER_ID":7,"SECONDS":1}]}')]);
        $client = new BitrixClient($http, 'https://example.bitrix24.ru', '1', 'secret');
        self::assertCount(51, $client->timeEntries(42, 7, new ReportPeriod('2026-09-01', '2026-09-30', 'UTC')));
    }
}
