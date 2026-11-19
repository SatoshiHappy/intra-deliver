<?php

namespace App\Queries\Ai\Api;

use App\Enums\Ai\AiRunStopReason;

/**
 * 会話の型の実行の読み取りが加えて返す物(docs の 設計/AI/エージェントの実行/口と契約.md 9.12 の実行の読み取り)。見られるのは依頼者だけで、
 * どれも原文(使用量・費用・ステップの送った文は返さない)。
 *
 * @phpstan-type RunRecord array{module: string, module_name: string|null, id: int, name: string|null}
 */
final readonly class ConversationRunView
{
    /**
     * @param  string|null  $stopBudgetPeriod  費用の上限で止めたときの超えた期間
     * @param  list<array{step_id: int, text: string}>  $partialText  途中の文(ステップごと)
     * @param  array{seconds: int, attempt: int}|null  $retryWait  送り直しを待っている間の待つ長さと何回目か
     * @param  list<ToolCallView>  $toolCalls  その実行に属する道具の行と、その実行が答えと結果を書いた行
     * @param  list<RunRecord>  $records  道具の結果に入ったレコード(表示の名前は原文の期限まで)
     */
    public function __construct(
        public ?AiRunStopReason $stopReason,
        public ?string $stopBudgetPeriod,
        public array $partialText,
        public ?array $retryWait,
        public bool $compacted,
        public array $toolCalls,
        public array $records,
    ) {}
}
