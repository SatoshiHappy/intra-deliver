<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\ConversationItem;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiRunStopReason;
use App\Models\Ai\AiToolCall;
use LogicException;

/**
 * 書く時の部品の、書く前の止める印と時間の上限(docs の 設計/AI/エージェントの実行/道具の包みと止める.md 9.5・設計/AI/実行の記録.md 2.4 の T7 の
 * 取り消し)。まだ書いていない件は、呼ばれた時の止めた結果と同じ形にする。
 */
class ToolWriteStops
{
    public function __construct(private readonly ToolCallRows $rows) {}

    /**
     * 止める印か時間の上限で、この件と後ろの件を取り消しにする(2.4 の T7 の取り消し)。
     *
     * @param  list<AiToolCall>  $rows
     * @param  list<array{callId: string, toolCallId: int, item: ConversationItem}>  $results
     */
    public function stopped(ToolStep $step, array $rows, AiRunStopReason $reason, array $results): ToolStepOutcome
    {
        $template = match ($reason) {
            AiRunStopReason::User => AiInstructionTemplate::UserCancelled,
            AiRunStopReason::TimeLimit => AiInstructionTemplate::StoppedByTimeLimit,
            default => throw new LogicException("書く前で止める理由 {$reason->value} は無い"),
        };
        $interruption = $reason === AiRunStopReason::User ? ConversationItem::interruption(true) : null;
        $cancelled = fn (AiToolCall $row): ConversationItem => ConversationItem::toolResult(
            $row->call_id,
            $template,
            [],
            null,
            true,
        );
        $entries = array_map(fn (AiToolCall $row): array => [$row, $cancelled($row)], $rows);

        if (! $this->rows->cancelledAnswers($step, $entries, $interruption === null ? [] : [$interruption])) {
            return new ToolStepOutcome($results, conversationMissing: true);
        }

        foreach ($entries as [$row, $item]) {
            $results[] = ['callId' => $row->call_id, 'toolCallId' => $row->id, 'item' => $item];
        }

        return new ToolStepOutcome($results, $interruption, $reason);
    }
}
