<?php

namespace App\Bitrix;

final class BitrixFailure extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly ?int $retryAt = null)
    {
        parent::__construct('Ошибка чтения Битрикс24: '.$errorCode);
    }
}
