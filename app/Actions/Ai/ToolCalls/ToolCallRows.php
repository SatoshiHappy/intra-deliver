<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\ConversationItem;
use App\Enums\Ai\AiToolCallDecision;
use App\Enums\Ai\AiToolCallStatus;
use App\Enums\Ai\AiToolDenialReason;
use App\Models\Ai\AiToolCall;
use App\Models\Ai\AiToolCallText;
use Illuminate\Support\Facades\DB;

/**
 * 道具を呼ぶ包みが書く道具の行と原文と、会話の保存へのその結果の足す(docs の 設計/AI/実行の記録.md 2.4・設計/AI/エージェントの実行/道具の包みと止める.md
 * 9.4 の 6)。許すの呼び出しの行を道具の前に処理中で確定するのは、worker が落ちたときに結果不明として拾えるようにするため(9.8)。
 *
 * @phpstan-type Finished array{position: int, call: ModelToolCall, decision: AiToolCallDecision|null,
 *     status: AiToolCallStatus, reason: AiToolDenialReason|null, item: ConversationItem, text: string|null}
 */
class ToolCallRows
{
    /**
     * 終わった呼び出しの行と原文を書き、項目(続けて $after)を会話の保存に足す。会話が無ければ行は書いたまま null(足さない)。
     *
     * @param  list<Finished>  $entries
     * @param  list<ConversationItem>  $after
     * @return list<int>|null 行の id(渡した順)
     */
    public function finished(ToolStep $step, array $entries, array $after = []): ?array
    {
        return DB::transaction(function () use ($step, $entries, $after): ?array {
            $ids = [];

            foreach ($entries as $entry) {
                $ids[] = $this->insert($step, $entry['position'], $entry['call'], [
                    'decision' => $entry['decision'],
                    'decision_reason' => $entry['reason']?->value,
                    'status' => $entry['status'],
                    'finished_at' => now(),
                ], $entry['text'])->id;
            }

            $items = [...array_column($entries, 'item'), ...$after];

            return $step->store->append($step->run->id, $items) ? $ids : null;
        });
    }

    /** 許すの呼び出しの行を処理中で書いて確定する(道具の中から処理中で読める) */
    public function running(ToolStep $step, int $position, ModelToolCall $call): AiToolCall
    {
        return DB::transaction(fn (): AiToolCall => $this->insert($step, $position, $call, [
            'decision' => AiToolCallDecision::Allow,
            'status' => AiToolCallStatus::Running,
        ], null));
    }

    /**
     * 処理中の行を終え(済みか道具の誤り)、原文の結果と読んだレコードを書き、項目を会話の保存に足す。会話が無ければ false。
     *
     * @param  list<array{module: string, id: int, name: string}>  $records
     */
    public function completed(
        ToolStep $step,
        AiToolCall $row,
        AiToolCallStatus $status,
        ?string $text,
        array $records,
        ConversationItem $item,
    ): bool {
        return DB::transaction(function () use ($step, $row, $status, $text, $records, $item): bool {
            $read = array_map(
                fn (array $record): array => ['module' => $record['module'], 'id' => $record['id']],
                $records,
            );
            $row->update(['status' => $status, 'finished_at' => now(), 'records' => $read]);
            AiToolCallText::query()->whereKey($row->id)->update([
                'result_text' => $text,
                'record_names' => json_encode($records, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);

            return $step->store->append($step->run->id, [$item]);
        });
    }

    /**
     * 行と原文を足す(原文の引数はモデルが渡したまま)。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function insert(
        ToolStep $step,
        int $position,
        ModelToolCall $call,
        array $attributes,
        ?string $text,
    ): AiToolCall {
        $row = AiToolCall::query()->create([
            'ai_run_id' => $step->run->id,
            'ai_run_step_id' => $step->step->id,
            'position' => $position,
            'call_id' => $call->id,
            'tool_name' => $call->name,
            'records' => [],
            ...$attributes,
        ]);
        AiToolCallText::query()->create([
            'ai_tool_call_id' => $row->id,
            'arguments' => $call->arguments,
            'result_text' => $text,
            'record_names' => [],
        ]);

        return $row;
    }
}
