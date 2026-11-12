<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\ConversationItem;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiRunStopReason;
use App\Enums\Ai\AiToolCallStatus;
use Closure;
use LogicException;

/**
 * 道具を呼ぶ包み(docs の 設計/AI/エージェントの実行/道具の包みと止める.md 9.4・9.5)。結果を定型の種類・値・本文に分けて保存し、送る文は送る時に
 * 組むのは、版を替えた後も前の結果を今の版で包めるようにするため。段ごとの扱いは {@see ToolCallStages}。
 *
 * @phpstan-import-type Finished from ToolCallRows
 */
class ToolCallWrapper
{
    public function __construct(private ToolCallStages $stages, private ToolCallRows $rows) {}

    /**
     * @param  list<ModelToolCall>  $calls  応答に入った順の呼び出し
     * @param  Closure(): ?AiRunStopReason  $stopped  止める印(利用者)か時間の上限に当たったときの理由
     *
     * @throws LogicException 呼び出しの id が空のとき・止めた理由が利用者と時間の上限のほかのとき(作りの誤り)
     */
    public function run(ToolStep $step, array $calls, Closure $stopped): ToolStepOutcome
    {
        $results = [];
        $prior = [];

        foreach ($calls as $index => $call) {
            if ($call->id === '') {
                throw new LogicException('道具の呼び出しの id が空(振るのはブロックが確定する所)');
            }

            $granted = $step->tools->find($call->name);
            $reason = $granted === null ? null : $stopped();

            if ($reason !== null) {
                return $this->stopped($step, array_slice($calls, $index), $index + 1, $reason, $results);
            }

            [$result, $priorCall] = $this->stages->through($step, $index + 1, $call, $granted, $prior);

            if ($result === null) {
                return new ToolStepOutcome($results, conversationMissing: true);
            }

            $results[] = $result;
            $prior[] = $priorCall;
        }

        return new ToolStepOutcome($results);
    }

    /**
     * 結果の無い呼び出しのすべてに止めた結果を付け、道具の行を取り消しにする(2.4 の T1)。
     *
     * @param  list<ModelToolCall>  $calls  止めた呼び出しとその後ろ
     * @param  list<array{callId: string, toolCallId: int, item: ConversationItem}>  $results
     */
    private function stopped(
        ToolStep $step,
        array $calls,
        int $position,
        AiRunStopReason $reason,
        array $results,
    ): ToolStepOutcome {
        $template = match ($reason) {
            AiRunStopReason::User => AiInstructionTemplate::UserCancelled,
            AiRunStopReason::TimeLimit => AiInstructionTemplate::StoppedByTimeLimit,
            default => throw new LogicException("道具の前で止める理由 {$reason->value} は無い"),
        };
        $interruption = $reason === AiRunStopReason::User ? ConversationItem::interruption(true) : null;
        $entries = [];

        foreach ($calls as $offset => $call) {
            $entries[] = $this->cancelled($position + $offset, $call, $template);
        }

        $ids = $this->rows->finished($step, $entries, $interruption === null ? [] : [$interruption]);

        if ($ids === null) {
            return new ToolStepOutcome($results, conversationMissing: true);
        }

        foreach ($entries as $offset => $entry) {
            $results[] = ['callId' => $entry['call']->id, 'toolCallId' => $ids[$offset], 'item' => $entry['item']];
        }

        return new ToolStepOutcome($results, $interruption, $reason);
    }

    /**
     * @return Finished
     */
    private function cancelled(int $position, ModelToolCall $call, AiInstructionTemplate $template): array
    {
        return [
            'position' => $position,
            'call' => $call,
            'decision' => null,
            'status' => AiToolCallStatus::Cancelled,
            'reason' => null,
            'item' => ConversationItem::toolResult($call->id, $template, [], null, true),
            'text' => null,
        ];
    }
}
