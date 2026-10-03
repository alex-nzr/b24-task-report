<?php

namespace App\Report;

use App\Bitrix\BitrixClient;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class EmployeeDirectory
{
    public function __construct(private BitrixClient $bitrix, private CacheInterface $cache)
    {
    }

    public function all(): array
    {
        return $this->cache->get('employee_directory', function (ItemInterface $item): array
        {
            $item->expiresAfter(900);
            return $this->bitrix->employees();
        });
    }
}
