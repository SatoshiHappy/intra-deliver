<?php

namespace App\Queries\Ai\Api;

use App\Enums\Ai\AiToolCallAnswer;
use App\Enums\Ai\AiToolCallDecision;
use App\Enums\Ai\AiToolCallStatus;

/**
 * 会話の型の実行の読み取りの道具の呼び出し 1 件(docs の 設計/AI/エージェントの実行/口と契約.md 9.12 の実行の読み取り、行 = 設計/AI/実行の記録.md
 * 2.4)。引数・確認の内容・結果は原文で、期限で消えた後は null。
 */
final readonly class ToolCallView
{
    /**
     * @param  int  $runId  属する実行
     * @param  mixed  $arguments  モデルが渡した引数のまま(object でないこともある)
     * @param  array<array-key, mixed>|null  $confirmation  確認の内容
     */
    public function __construct(
        public int $runId,
        public string $callId,
        public string $name,
        public AiToolCallStatus $status,
        public ?AiToolCallDecision $decision,
        public ?string $decisionReason,
        public mixed $arguments,
        public ?array $confirmation,
        public ?AiToolCallAnswer $answer,
        public ?string $result,
    ) {}
}
