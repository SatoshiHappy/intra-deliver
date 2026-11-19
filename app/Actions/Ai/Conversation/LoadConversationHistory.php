<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\AddedTextValues;
use App\Actions\Ai\Core\TransientText;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;
use App\Models\Ai\AiRun;
use App\Queries\Ai\RunChainStart;

/**
 * 会話のジョブの 4(履歴を読み、読み込みの直しを当て、足す文の値を作る = docs の 設計/AI/エージェントの実行/README.md 9.1 のジョブの順)。
 * 今の実行に結ばれた項目(依頼の時に ① が書いた利用者の文 = 9.12)は、直しの後につなぐ。結果の分からない書き込みの知らせは、書く道具と一緒に
 * 組む(読む道具の結果不明は知らせない = 設計/AI/エージェントの実行/途中の表示と上限.md 9.8)。
 */
final readonly class LoadConversationHistory
{
    public function __construct(private RunChainStart $chainStart) {}

    /**
     * @param  list<ConversationItem>  $items  会話の保存の口が返した項目(会話が無いときは呼び手が止めた(利用者)にする)
     * @param  list<string>  $awaitingCallIds  答えの実行の確認待ちの呼び出し(結果がまだ無いが外さない)
     * @return array{list<ConversationItem|TransientText>, AddedTextValues}
     */
    public function execute(AiRun $run, array $items, array $awaitingCallIds = []): array
    {
        $previous = $run->previous_run_id === null ? null : AiRun::query()->find($run->previous_run_id);
        $workerLost = $previous?->status === AiRunStatus::Failed
            && $previous->error_code === AiRunErrorCode::WorkerLost;
        $current = $run->answer === null ? array_slice($items, -1) : [];
        $before = array_slice($items, 0, count($items) - count($current));

        return [
            [...HistoryRepair::repaired($before, $workerLost, $awaitingCallIds), ...$current],
            new AddedTextValues($this->chainStart->firstRunDate($run->id)),
        ];
    }
}
