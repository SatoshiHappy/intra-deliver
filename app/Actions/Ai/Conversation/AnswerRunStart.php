<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Api\ConversationStore;
use App\Actions\Ai\Core\CoreToolCall;
use App\Actions\Ai\Core\TransientText;
use App\Enums\Ai\AiConversationAnswer;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiToolCallAnswer;
use App\Enums\Ai\AiToolCallStatus;
use App\Enums\Ai\ConversationItemType;
use App\Models\Ai\AiAgentDefinition;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiToolCall;
use App\Models\Ai\AiToolCallText;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * 答えの実行の始め(docs の 設計/AI/エージェントの実行/README.md 9.1 のジョブの 4 と 9.3)。前の実行の確認待ちの件を引いて確かめ、「やめる」なら
 * 断りを書き、「実行」なら承認した件を中核に渡す形にする。
 */
final class AnswerRunStart
{
    /**
     * 前の実行の確認待ちの道具の行(呼び出しの順)。
     *
     * @return list<AiToolCall>
     *
     * @throws LogicException 答えの実行でないとき(作りの誤り)
     */
    public function awaiting(AiRun $run): array
    {
        $previous = $run->previous_run_id ?? throw new LogicException("実行 {$run->id} は答えの実行でない");

        return array_values(AiToolCall::query()
            ->where('ai_run_id', $previous)
            ->where('status', AiToolCallStatus::AwaitingConfirmation)
            ->orderBy('ai_run_step_id')
            ->orderBy('position')
            ->get()
            ->all());
    }

    /**
     * 確かめてから、「やめる」なら断りを書いて null(止めた(利用者)で終える)、「実行」なら承認した件と先に動かす呼び出し。
     *
     * @param  list<AiToolCall>  $rows  {@see self::awaiting()} の行
     * @param  list<ConversationItem|TransientText>  $history  取り消しの印が指す応答の項目を外した後の履歴
     * @return array{AnswerCalls, list<CoreToolCall>}|null
     *
     * @throws AnswerNotConfirmed 確かめが合わないとき
     */
    public function start(
        AiRun $run,
        ConversationStore $store,
        AiAgentDefinition $definition,
        array $rows,
        array $history,
    ): ?array {
        $texts = AiToolCallText::query()->whereKey(array_map(fn (AiToolCall $row): int => $row->id, $rows))->get()
            ->keyBy('ai_tool_call_id');
        $this->check($run, $rows, $texts, $history);

        if ($run->answer === AiConversationAnswer::Reject) {
            $this->reject($run, $store, $rows);

            return null;
        }

        return $this->approved($definition, $rows, $texts);
    }

    /**
     * 同じ呼び出しかの確かめ(利用者がカードで見た件だけを書くため)。
     *
     * @param  list<AiToolCall>  $rows
     * @param  Collection<int, AiToolCallText>  $texts  行の id → 原文の行
     * @param  list<ConversationItem|TransientText>  $history
     *
     * @throws AnswerNotConfirmed 合わないとき
     */
    private function check(AiRun $run, array $rows, Collection $texts, array $history): void
    {
        if ($rows === []) {
            throw new AnswerNotConfirmed("答えの実行 {$run->id} の前の実行に確認待ちの呼び出しが無い");
        }

        $calls = self::callsIn($history);

        foreach ($rows as $row) {
            $text = $texts->get($row->id);
            $same = $text !== null && $text->confirmation !== null && array_key_exists($row->call_id, $calls)
                && self::canonical($calls[$row->call_id]) === self::canonical($text->arguments);

            if (! $same) {
                throw new AnswerNotConfirmed("答えの実行 {$run->id} の確認待ちの呼び出し {$row->call_id} が会話の保存と合わない");
            }
        }
    }

    /**
     * 「やめる」(9.3 の H2)。
     *
     * @param  list<AiToolCall>  $rows
     */
    private function reject(AiRun $run, ConversationStore $store, array $rows): void
    {
        $results = array_map(
            fn (AiToolCall $row): ConversationItem => ConversationItem::toolResult(
                $row->call_id,
                AiInstructionTemplate::ToolUseRejected,
                [],
                null,
                true,
            ),
            $rows,
        );

        DB::transaction(function () use ($run, $store, $rows, $results): void {
            $updated = AiToolCall::query()
                ->whereKey(array_map(fn (AiToolCall $row): int => $row->id, $rows))
                ->where('status', AiToolCallStatus::AwaitingConfirmation)
                ->update([
                    'answer' => AiToolCallAnswer::Reject,
                    'answer_run_id' => $run->id,
                    'status' => AiToolCallStatus::Rejected,
                    'finished_at' => now(),
                ]);

            if ($updated !== count($rows)) {
                throw new LogicException("答えの実行 {$run->id} が断る確認待ちの行が、ほかの経路で終わっていた");
            }

            // 会話が無くても行は断られたで確定する(どちらでも止めた(利用者)で終えるので、足せたかは使わない =
            // 設計/AI/エージェントの実行/道具の包みと止める.md 9.4 の会話が無いと同じ)
            $store->append($run->id, [...$results, ConversationItem::interruption(true)]);
        });
    }

    /**
     * 「実行」(9.3 の H1): 承認した件と、中核に先に動かす呼び出しとして渡す形。
     *
     * @param  list<AiToolCall>  $rows
     * @param  Collection<int, AiToolCallText>  $texts
     * @return array{AnswerCalls, list<CoreToolCall>}
     */
    private function approved(AiAgentDefinition $definition, array $rows, Collection $texts): array
    {
        $calls = array_map(fn (AiToolCall $row): CoreToolCall => new CoreToolCall(
            $row->call_id,
            $row->tool_name,
            (array) $texts->get($row->id)?->arguments,
        ), $rows);
        $keyed = array_combine(array_map(fn (AiToolCall $row): string => $row->call_id, $rows), $rows);

        return [new AnswerCalls($definition, $keyed, $texts->all()), $calls];
    }

    /**
     * モデルの文の呼び出しの引数(呼び出しの id ごとに最初の呼び出し = 設計/AI/実行の記録.md 2.4 の id が重なった呼び出し)。
     *
     * @param  list<ConversationItem|TransientText>  $history
     * @return array<string, array<array-key, mixed>>
     */
    private static function callsIn(array $history): array
    {
        $calls = [];

        foreach ($history as $item) {
            if (! $item instanceof ConversationItem || $item->type !== ConversationItemType::ModelText) {
                continue;
            }

            foreach ($item->content['blocks'] as $block) {
                if ($block['type'] === 'tool_call' && ! array_key_exists($block['id'], $calls)) {
                    $calls[$block['id']] = $block['arguments'];
                }
            }
        }

        return $calls;
    }

    /** 比べる形(連想配列の鍵の順をそろえる。保存先によって鍵の順が変わるため。列の順は保つ) */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::canonical(...), $value);

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
