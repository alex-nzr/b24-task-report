<?php

namespace App\Bitrix;

use App\Report\ReportPeriod;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class BitrixClient
{
    private array $resetAt = [];

    public function __construct(
        private HttpClientInterface $http,
        private string $portalUrl,
        private string $webhookUserId,
        private string $webhookToken,
        private ?RateLimiterFactory $limiter = null,
    )
    {
    }

    public function tasks(int $afterId, ?int $maxId, bool $descending = false): array
    {
        $filter = [['id', '>', $afterId]];
        if ($maxId !== null)
        {
            $filter[] = ['id', '<=', $maxId];
        }
        $response = $this->call('tasks.task.list', [
            'select' => ['id', 'title', 'responsibleId', 'status', 'groupId', 'activity'],
            'filter' => $filter,
            'order' => ['id' => $descending ? 'DESC' : 'ASC'],
            'pagination' => ['page' => 1, 'limit' => $descending ? 1 : 50],
        ], true);
        if (!isset($response['result']['items']) || !is_array($response['result']['items']))
        {
            throw new BitrixFailure('INVALID_RESPONSE');
        }
        return $response['result']['items'];
    }

    public function selectedTasks(int $employeeId, ReportPeriod $period, int $afterId, ?int $maxId, bool $descending = false): array
    {
        $filter = ['>ID' => $afterId];
        if ($maxId !== null)
        {
            $filter['<=ID'] = $maxId;
        }
        $filter['>=ACTIVITY_DATE'] = $period->start->format(DATE_ATOM);
        // DOER includes responsible/accomplice without the OR + ACCOMPLICE inner-join trap.
        $filter['DOER'] = $employeeId;
        $response = $this->call('tasks.task.list', [
            'select' => ['ID', 'TITLE', 'RESPONSIBLE_ID', 'STATUS', 'ACCOMPLICES'],
            'filter' => $filter,
            'order' => ['ID' => $descending ? 'DESC' : 'ASC'],
            'start' => 0,
        ], false);
        $items = $response['result']['tasks'] ?? null;
        $total = $response['total'] ?? null;
        if (!is_array($items) || !array_is_list($items) || (!is_int($total) && !is_string($total)) || filter_var($total, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483647]]) === false)
        {
            throw new BitrixFailure('INVALID_RESPONSE');
        }
        $tasks = [];
        $previousId = null;
        foreach ($items as $item)
        {
            if (!is_array($item) || !isset($item['id'], $item['title'], $item['responsibleId'], $item['status'], $item['accomplices']) || !is_string($item['title']) || (!is_string($item['status']) && !is_int($item['status'])) || !is_array($item['accomplices']) || !array_is_list($item['accomplices']))
            {
                throw new BitrixFailure('INVALID_TASK_PAGE');
            }
            foreach (['id', 'responsibleId'] as $field)
            {
                if ((!is_int($item[$field]) && !is_string($item[$field])) || filter_var($item[$field], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) === false)
                {
                    throw new BitrixFailure('INVALID_TASK_PAGE');
                }
            }
            $id = (int) $item['id'];
            if ($id <= $afterId || ($maxId !== null && $id > $maxId) || ($previousId !== null && ($descending ? $id >= $previousId : $id <= $previousId)))
            {
                throw new BitrixFailure('INVALID_TASK_PAGE');
            }
            $previousId = $id;
            $accomplices = [];
            foreach ($item['accomplices'] as $participant)
            {
                if ((!is_int($participant) && !is_string($participant)) || filter_var($participant, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) === false)
                {
                    throw new BitrixFailure('INVALID_TASK_PAGE');
                }
                $accomplices[] = (int) $participant;
            }
            $tasks[] = ['id' => (int) $item['id'], 'title' => $item['title'], 'responsibleId' => (int) $item['responsibleId'], 'status' => (string) $item['status'], 'accomplices' => $accomplices];
        }
        if (($tasks === []) !== ((int) $total === 0))
        {
            throw new BitrixFailure('INVALID_RESPONSE');
        }
        return ['tasks' => $tasks, 'total' => (int) $total];
    }

    public function participants(int $taskId): array
    {
        $response = $this->call('tasks.task.get', ['id' => $taskId, 'select' => ['id', 'responsible.id', 'accomplices.id', 'auditors.id']], true);
        if (!isset($response['result']['item']) || !is_array($response['result']['item']))
        {
            throw new BitrixFailure('INVALID_RESPONSE');
        }
        return $response['result']['item'];
    }

    public function timeEntries(int $taskId, int $employeeId, ReportPeriod $period): array
    {
        $entries = [];
        $page = 1;
        $previousLastId = 0;
        do
        {
            $response = $this->call('task.elapseditem.getlist', [
                $taskId,
                ['ID' => 'asc'],
                ['USER_ID' => $employeeId, '>=CREATED_DATE' => $period->start->format(DATE_ATOM), '<CREATED_DATE' => $period->end->format(DATE_ATOM)],
                ['ID', 'TASK_ID', 'USER_ID', 'SECONDS', 'CREATED_DATE', 'DATE_START', 'DATE_STOP', 'COMMENT_TEXT'],
                ['NAV_PARAMS' => ['nPageSize' => 50, 'iNumPage' => $page++]],
            ], false);
            $items = $response['result'] ?? null;
            if (!is_array($items) || !array_is_list($items))
            {
                throw new BitrixFailure('INVALID_RESPONSE');
            }
            foreach ($items as $entry)
            {
                if ((int) ($entry['TASK_ID'] ?? 0) !== $taskId)
                {
                    throw new BitrixFailure('INVALID_TASK_TIME');
                }
                $entries[] = $entry;
            }
            if ($items !== [])
            {
                $lastId = (int) ($items[array_key_last($items)]['ID'] ?? 0);
                if ($lastId <= $previousLastId)
                {
                    throw new BitrixFailure('INVALID_PAGINATION');
                }
                $previousLastId = $lastId;
            }
        }
        while (count($items) === 50);
        return $entries;
    }

    public function calendarSettings(): array
    {
        $response = $this->call('calendar.settings.get', [], false);
        if (!is_array($response['result']))
        {
            throw new BitrixFailure('INVALID_CALENDAR');
        }
        return $response['result'];
    }

    public function employees(): array
    {
        $employees = [];
        $start = 0;
        $visited = [];
        do
        {
            if (isset($visited[$start]))
            {
                throw new BitrixFailure('INVALID_PAGINATION');
            }
            $visited[$start] = true;
            $response = $this->call('user.get', ['filter' => ['USER_TYPE' => 'employee'], 'sort' => 'ID', 'order' => 'ASC', 'start' => $start], false);
            if (!isset($response['result']) || !is_array($response['result']))
            {
                throw new BitrixFailure('INVALID_RESPONSE');
            }
            foreach ($response['result'] as $user)
            {
                $id = (int) ($user['ID'] ?? 0);
                if ($id > 0)
                {
                    $employees[$id] = ['id' => $id, 'name' => trim(($user['LAST_NAME'] ?? '').' '.($user['NAME'] ?? '').' '.($user['SECOND_NAME'] ?? '')) ?: '#'.$id];
                }
            }
            $start = isset($response['next']) ? (int) $response['next'] : null;
        }
        while ($start !== null);
        return $employees;
    }

    private function call(string $method, array $parameters, bool $v3): array
    {
        $url = parse_url($this->portalUrl);
        if (!$url || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || !in_array($url['path'] ?? '', ['', '/'], true) || !ctype_digit($this->webhookUserId) || $this->webhookToken === '')
        {
            throw new BitrixFailure('WEBHOOK_NOT_CONFIGURED');
        }
        $this->limiter?->create('portal')->reserve(1)->wait();
        $endpoint = rtrim($this->portalUrl, '/').'/rest/'.($v3 ? 'api/' : '').rawurlencode($this->webhookUserId).'/'.rawurlencode($this->webhookToken).'/'.$method;
        try
        {
            $response = $this->http->request('POST', $endpoint, ['json' => $parameters, 'max_redirects' => 0, 'timeout' => 55, 'max_duration' => 55]);
            $status = $response->getStatusCode();
            $body = json_decode($response->getContent(false), true, 512, JSON_THROW_ON_ERROR);
        }
        catch (TransportExceptionInterface)
        {
            throw new BitrixFailure('NETWORK_ERROR', time() + 2);
        }
        catch (\JsonException)
        {
            if ($status >= 500 || $status === 429)
            {
                throw new BitrixFailure('HTTP_'.$status, time() + 2);
            }
            throw new BitrixFailure('INVALID_RESPONSE');
        }
        if (!is_array($body))
        {
            throw new BitrixFailure('INVALID_RESPONSE');
        }
        if (isset($body['time']['operating_reset_at']))
        {
            $this->resetAt[$method] = (int) $body['time']['operating_reset_at'];
        }
        $code = $body['error'] ?? null;
        if (is_array($code))
        {
            $code = $code['code'] ?? 'API_ERROR';
        }
        if ($code || $status >= 300)
        {
            $code = is_string($code) && preg_match('/^[A-Z0-9_]+$/D', $code) ? $code : 'HTTP_'.$status;
            $retryAt = null;
            if ($code === 'OPERATION_TIME_LIMIT')
            {
                $retryAt = max(time() + 2, ($this->resetAt[$method] ?? time() + 60) + 2);
            }
            elseif ($code === 'QUERY_LIMIT_EXCEEDED' || $status >= 500 || $status === 429)
            {
                $retryAt = time() + 2;
            }
            throw new BitrixFailure($code, $retryAt);
        }
        if (!array_key_exists('result', $body))
        {
            throw new BitrixFailure('INVALID_RESPONSE');
        }
        return $body;
    }
}
