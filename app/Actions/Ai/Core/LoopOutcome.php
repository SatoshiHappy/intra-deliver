<?php

namespace App\Actions\Ai\Core;

use App\Actions\Ai\Api\ConversationItem;

/**
 * ループの返す物(docs の 設計/AI/会話の中核の口と受け手.md 13.3 の口の表): 終わり方と、その実行の中の送る履歴(検査と調べのための物。
 * 保存の元にしない。保存は知らせの口で済んでいる)。
 */
final readonly class LoopOutcome
{
    /**
     * @param  list<ConversationItem|TransientText>  $sentHistory
     */
    public function __construct(
        public LoopEnding $ending,
        public array $sentHistory,
    ) {}
}
