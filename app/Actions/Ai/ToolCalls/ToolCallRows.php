<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\ConversationItem;
use App\Enums\Ai\AiToolCallAnswer;
use App\Enums\Ai\AiToolCallDecision;
use App\Enums\Ai\AiToolCallStatus;
use App\Enums\Ai\AiToolDenialReason;
use App\Enums\Ai\AiToolWriteDenial;
use App\Models\Ai\AiToolCall;
use App\Models\Ai\AiToolCallText;
use Illuminate\Support\Facades\DB;
use LogicException;

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

    /**
     * 問うの呼び出しの行を確認待ちで書き、確認の内容と書く値の引数と直すの版を残す(2.4 の T5)。会話の保存には足さない(結果は答えの後 =
     * 9.4 の 5)。
     *
     * @param  array<string, mixed>  $confirmation
     * @param  array<string, mixed>  $written  書く値の引数(書く時にこの値で書く = 10.5)
     */
    public function asked(
        ToolStep $step,
        int $position,
        ModelToolCall $call,
        array $confirmation,
        array $written,
        ?string $version,
    ): AiToolCall {
        $texts = [
            'confirmation' => json_encode($confirmation, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'written_arguments' => json_encode((object) $written, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];

        return DB::transaction(function () use ($step, $position, $call, $texts, $version): AiToolCall {
            $row = $this->insert($step, $position, $call, [
                'decision' => AiToolCallDecision::Ask,
                'status' => AiToolCallStatus::AwaitingConfirmation,
                'confirmation_version' => $version,
            ], null);
            AiToolCallText::query()->whereKey($row->id)->update($texts);

            return $row;
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
     * 確認待ちの行に実行の答えを書いて処理中にし、確定する(書く前。worker が落ちたときに結果不明として拾えるように = 2.4 の T7)。
     * 確認待ちの行だけを条件にして書くのは、同じ件を 2 つの答えの実行が書かないため。
     *
     * @throws LogicException 行が確認待ちでないとき(呼び手が確認待ちの件だけを渡す)
     */
    public function approved(ToolStep $step, AiToolCall $row): void
    {
        $claimed = DB::transaction(fn (): int => AiToolCall::query()
            ->whereKey($row->id)
            ->where('status', AiToolCallStatus::AwaitingConfirmation)
            ->update([
                'answer' => AiToolCallAnswer::Approve,
                'answer_run_id' => $step->run->id,
                'status' => AiToolCallStatus::Running,
            ]));

        if ($claimed !== 1) {
            throw new LogicException("道具の行 {$row->id} は確認待ちでないので書けない");
        }

        $row->refresh();
    }

    /**
     * 書く前に止めた件の行をまとめて取り消しにし、項目(続けて $after)を会話の保存に足す(1 つのトランザクション = 一部だけ終えない)。
     * 会話が無ければ行は書いたまま false。
     *
     * @param  list<array{AiToolCall, ConversationItem}>  $entries
     * @param  list<ConversationItem>  $after
     */
    public function cancelledAnswers(ToolStep $step, array $entries, array $after): bool
    {
        return DB::transaction(function () use ($step, $entries, $after): bool {
            $ids = array_map(fn (array $entry): int => $entry[0]->id, $entries);
            self::ownAnswers($step, $ids, ['status' => AiToolCallStatus::Cancelled, 'finished_at' => now()]);

            return $step->store->append($step->run->id, [...array_column($entries, 1), ...$after]);
        });
    }

    /**
     * 実行の答えの行を終え(2.4 の T7 の済み・断られた・道具の誤り・取り消し)、書く時の断りの理由と原文の結果と書いたレコードを書き、項目
     * (続けて $after)を会話の保存に足す。答えと答えを書いた実行も書く(書く前に終わる件は処理中を通らない)。会話が無ければ false。
     *
     * @param  list<array{module: string, id: int, name: string}>  $records
     * @param  list<ConversationItem>  $after
     */
    public function answered(
        ToolStep $step,
        AiToolCall $row,
        AiToolCallStatus $status,
        ?AiToolWriteDenial $denial,
        ?string $text,
        array $records,
        ConversationItem $item,
        array $after = [],
    ): bool {
        return DB::transaction(function () use ($step, $row, $status, $denial, $text, $records, $item, $after): bool {
            self::ownAnswers($step, [$row->id], [
                'status' => $status,
                'write_denial_reason' => $denial,
                'finished_at' => now(),
                'records' => json_encode(array_map(
                    fn (array $record): array => ['module' => $record['module'], 'id' => $record['id']],
                    $records,
                ), JSON_THROW_ON_ERROR),
            ]);
            AiToolCallText::query()->whereKey($row->id)->update([
                'result_text' => $text,
                'record_names' => json_encode($records, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);

            return $step->store->append($step->run->id, [$item, ...$after]);
        });
    }

    /**
     * 答えの実行の行の終わりを書く。書けるのは確認待ちの行か、この答えの実行が処理中にした行だけ(同じ件を 2 つの答えの実行が書かない)。
     *
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $attributes
     *
     * @throws LogicException どれかの行がどちらでもないとき(作りの誤り。受け手から投げる)
     */
    private static function ownAnswers(ToolStep $step, array $ids, array $attributes): void
    {
        $runId = $step->run->id;
        $updated = AiToolCall::query()
            ->whereKey($ids)
            ->where(fn ($query) => $query->where('status', AiToolCallStatus::AwaitingConfirmation)
                ->orWhere(fn ($running) => $running->where('status', AiToolCallStatus::Running)
                    ->where('answer_run_id', $runId)))
            ->update(['answer' => AiToolCallAnswer::Approve, 'answer_run_id' => $runId, ...$attributes]);

        if ($updated !== count($ids)) {
            throw new LogicException('道具の行 '.implode('・', $ids).' に、確認待ちでも、この答えの実行が処理中にした行でもない行がある');
        }
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
