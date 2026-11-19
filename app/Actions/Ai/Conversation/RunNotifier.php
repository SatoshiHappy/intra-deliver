<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Api\ConversationStore;
use App\Actions\Ai\Core\AfterSendNotice;
use App\Actions\Ai\Core\AppendNotice;
use App\Actions\Ai\Core\BeforeSendNotice;
use App\Actions\Ai\Core\CoreNotice;
use App\Actions\Ai\Core\CoreNotifier;
use App\Actions\Ai\Core\ExchangeNotice;
use App\Actions\Ai\Core\NoticeReply;
use App\Actions\Ai\Core\RetryWaitEndedNotice;
use App\Actions\Ai\Core\RetryWaitStartedNotice;
use App\Actions\Ai\Core\SendKind;
use App\Actions\Ai\Core\StepFailedNotice;
use App\Actions\Ai\Core\StepStoppedNotice;
use App\Actions\Ai\Core\TextDeltaNotice;
use App\Actions\Ai\Core\ToolResultNotice;
use App\Actions\Ai\ToolCalls\ToolCallRows;
use App\Enums\Ai\AiToolCallStatus;
use App\Enums\Ai\ConversationItemType;
use App\Models\Ai\AiRun;
use App\Queries\Ai\RunChainCalls;
use LogicException;

/**
 * 知らせの口の中身(知らせごとの書き先 = docs の 設計/AI/会話の中核の口と受け手.md 13.3 の「知らせの口」)。会話の保存に足す物は ① の口へ、
 * ステップと途中の表示は実行の記録へ書く。呼び出しの id の重なりは実行の鎖をまたいで見られる繋ぎが見る(設計/AI/実行の記録.md 2.4)。
 */
final readonly class RunNotifier implements CoreNotifier
{
    public function __construct(
        private int $runId,
        private ConversationStore $store,
        private RunChainCalls $chainCalls,
        private StepRecorder $steps,
        private RunProgress $progress,
        private RunToolStep $toolStep,
        private ToolCallRows $rows,
    ) {}

    public function notify(CoreNotice $notice): NoticeReply
    {
        if ($notice instanceof AppendNotice) {
            return $this->append($notice->items);
        }

        match (true) {
            $notice instanceof BeforeSendNotice => $this->beforeSend($notice),
            $notice instanceof ExchangeNotice => $this->steps->exchange($notice),
            $notice instanceof TextDeltaNotice => $this->progress->delta($notice),
            $notice instanceof AfterSendNotice => $this->afterSend($notice),
            $notice instanceof StepFailedNotice => $this->ended(fn () => $this->steps->failed($notice)),
            $notice instanceof StepStoppedNotice => $this->ended(fn () => $this->steps->stopped($notice)),
            $notice instanceof RetryWaitStartedNotice => $this->progress->retryWaitStarted($notice),
            $notice instanceof RetryWaitEndedNotice => $this->progress->retryWaitEnded(),
            $notice instanceof ToolResultNotice => null,
            default => throw new LogicException('知らない知らせ: '.$notice::class),
        };

        return NoticeReply::accepted();
    }

    /**
     * 項目を書いてから、確定したモデルの文の呼び出しのうち鎖の道具の行と重なった物を返す(重なった呼び出しは中核が動かさない)。
     *
     * @param  list<ConversationItem>  $items
     */
    private function append(array $items): NoticeReply
    {
        $duplicates = $this->chainCalls->existing($this->runId, RunToolStep::callsIn($items));
        $results = array_values(array_filter($items, self::isToolResult(...)));
        $written = $results === []
            ? $this->store->append($this->runId, $items)
            : $this->withRows($results, array_slice($items, count($results)));

        if (! $written) {
            return NoticeReply::noConversation();
        }

        $this->toolStep->remember($items);
        $this->rememberCompaction($items);

        return $duplicates === [] ? NoticeReply::accepted() : NoticeReply::duplicateCalls($duplicates);
    }

    /** 回の答えか続きの送信は、その応答の呼び出しのステップになる */
    private function beforeSend(BeforeSendNotice $notice): void
    {
        $this->steps->beforeSend($notice);

        if ($notice->kind === SendKind::Turn || $notice->kind === SendKind::Continuation) {
            $this->toolStep->begin($notice->stepId);
        }
    }

    /**
     * 中核が受け手を通らずに付けた結果は、取り消しの道具の行と一緒に書く(設計/AI/実行の記録.md 2.4 の T1)。結果は知らせの先頭に並び、
     * 後ろは中断の印(中核の知らせ方)。承認した件は前の実行の行を取り消す(同じ 2.4 の T7)。
     *
     * @param  list<ConversationItem>  $results
     * @param  list<ConversationItem>  $after
     *
     * @throws LogicException 結果の後ろに結果がある(結果が先頭に並ばない)とき・承認した件とほかの件が混ざるとき
     */
    private function withRows(array $results, array $after): bool
    {
        if (array_filter($after, self::isToolResult(...)) !== []) {
            throw new LogicException('中核が付けた結果は知らせの先頭に並ぶ');
        }

        $answer = $this->toolStep->answer();
        $approved = array_values(array_filter(
            $results,
            fn (ConversationItem $item): bool => (bool) $answer?->has($item->content['call_id']),
        ));

        if ($answer !== null && $approved !== []) {
            $rows = array_map(fn (ConversationItem $item) => $answer->row($item->content['call_id']), $approved);

            return count($approved) === count($results)
                ? $this->rows->cancelledAnswers($this->toolStep->answerContext(), $rows, [...$results, ...$after])
                : throw new LogicException('中核が付けた結果に、承認した件とほかの件が混ざる');
        }

        $entries = array_map(fn (ConversationItem $item): array => [
            'position' => $this->toolStep->nextPosition(),
            'call' => $this->toolStep->callOf($item->content['call_id']),
            'decision' => null,
            'status' => AiToolCallStatus::Cancelled,
            'reason' => null,
            'item' => $item,
            'text' => null,
        ], $results);

        return $this->rows->finished($this->toolStep->toolStep(), $entries, $after) !== null;
    }

    /**
     * 要約の境目を書いたら、その実行で要約したことを書く(書く時 = 設計/AI/実行の記録.md 2.1 の「その実行で要約したこと」)。
     *
     * @param  list<ConversationItem>  $items
     */
    private function rememberCompaction(array $items): void
    {
        foreach ($items as $item) {
            if ($item->type === ConversationItemType::CompactBoundary) {
                AiRun::query()->whereKey($this->runId)->update(['compacted' => true]);

                return;
            }
        }
    }

    private static function isToolResult(ConversationItem $item): bool
    {
        return $item->type === ConversationItemType::ToolResult;
    }

    private function afterSend(AfterSendNotice $notice): void
    {
        if ($notice->finish !== null) {
            $this->progress->flush();
        }

        $this->steps->afterSend($notice);
    }

    private function ended(callable $write): void
    {
        $this->progress->flush();
        $write();
    }
}
