<?php

namespace App\Actions\Ai;

use App\Enums\Ai\AiRunErrorCode;
use RuntimeException;
use Throwable;

/**
 * 文字起こしが途中で止まった(内部。{@see TranscribeAudio} が投げ、{@see ExecuteTranscription} が失敗を書いて元の失敗を投げ直す)。
 * 分かった分の使用量・費用・原文を持つ(呼んだ区切りの費用は失敗でも数える = docs の 設計/AI/実行と費用.md 5.1)。
 */
final class TranscriptionStopped extends RuntimeException
{
    public function __construct(
        public readonly AiRunErrorCode $errorCode,
        public readonly Throwable $failure,
        public readonly RunResult $result,
    ) {
        parent::__construct($failure->getMessage(), 0, $failure);
    }
}
