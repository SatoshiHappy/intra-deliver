<?php

namespace App\Actions\Ai;

use App\Enums\Ai\AiRunErrorCode;
use RuntimeException;
use Throwable;

/**
 * LLM の呼び出しの失敗(内部。{@see LlmClient} が laravel/ai・HTTP の例外を包み、失敗の種類 provider / timeout を決める)。元の例外は previous。
 */
final class LlmCallFailed extends RuntimeException
{
    public function __construct(public readonly AiRunErrorCode $errorCode, Throwable $previous)
    {
        parent::__construct($previous->getMessage(), 0, $previous);
    }
}
