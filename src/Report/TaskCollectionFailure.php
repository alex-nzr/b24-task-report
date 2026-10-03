<?php

namespace App\Report;

use App\Bitrix\BitrixFailure;

final class TaskCollectionFailure extends \RuntimeException
{
    public function __construct(public readonly string $stage, public readonly BitrixFailure $failure, public readonly ?array $row = null)
    {
        parent::__construct('Не удалось обработать задачу: '.$failure->errorCode);
    }
}
