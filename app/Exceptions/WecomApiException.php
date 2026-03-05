<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 企微 API 返回错误时抛出的异常
 * 当 API 响应中 errcode != 0 时使用
 */
class WecomApiException extends RuntimeException
{
    public function __construct(
        public readonly int $errcode,
        public readonly string $errmsg,
        public readonly array $response,
    ) {
        parent::__construct("企微 API 错误 [{$errcode}]: {$errmsg}", $errcode);
    }
}
