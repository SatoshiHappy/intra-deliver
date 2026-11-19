<?php

namespace App\Queries\Ai;

use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunText;
use App\Models\Ai\AiToolCall;
use App\Queries\Ai\Api\ConversationRunView;
use App\Queries\Ai\Api\ToolCallView;
use App\Queries\Records\Api\RecordModuleDirectory;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * 会話の型の実行の読み取りが加えて返す物を読む(docs の 設計/AI/エージェントの実行/口と契約.md 9.12 の実行の読み取り)。業務モジュールの名前は
 * 定義の名前なので、読み取りの時に引き、記録に写さない(設計/AI/実行の記録.md 2.4)。
 *
 * @phpstan-import-type RunRecord from ConversationRunView
 */
final readonly class ConversationRunQuery
{
    public function __construct(private RecordModuleDirectory $modules) {}

    public function of(AiRun $run): ConversationRunView
    {
        /** @var list<AiToolCall> $rows */
        $rows = AiToolCall::query()->with('text')
            ->where(fn (Builder $query) => $query->where('ai_run_id', $run->id)->orWhere('answer_run_id', $run->id))
            ->orderBy('id')->get()->all();
        $partial = AiRunText::query()->find($run->id)?->partial_text;

        return new ConversationRunView(
            $run->stop_reason,
            $run->stop_budget_period,
            self::partialText($partial),
            $run->retry_wait_seconds === null || $run->retry_attempt === null
                ? null
                : ['seconds' => $run->retry_wait_seconds, 'attempt' => $run->retry_attempt],
            $run->compacted === true,
            array_map(self::toolCall(...), $rows),
            $this->records($rows),
        );
    }

    /**
     * 途中の文の要素を {step_id, text} の形に並べる(jsonb は鍵の順を保たない)。
     *
     * @return list<array{step_id: int, text: string}>
     *
     * @throws LogicException 要素の形が合わないとき(書くのは途中の文の書き手だけ)
     */
    private static function partialText(mixed $partial): array
    {
        $texts = [];

        foreach (is_array($partial) ? $partial : [] as $element) {
            $stepId = is_array($element) ? ($element['step_id'] ?? null) : null;
            $text = is_array($element) ? ($element['text'] ?? null) : null;
            $texts[] = is_int($stepId) && is_string($text)
                ? ['step_id' => $stepId, 'text' => $text]
                : throw new LogicException('途中の文の要素の形が合わない');
        }

        return $texts;
    }

    private static function toolCall(AiToolCall $row): ToolCallView
    {
        return new ToolCallView(
            $row->ai_run_id,
            $row->call_id,
            $row->tool_name,
            $row->status,
            $row->decision,
            $row->decision_reason,
            $row->text?->arguments,
            $row->text?->confirmation,
            $row->answer,
            $row->text?->result_text,
        );
    }

    /**
     * 行の読んだ・書いたレコード(重ねない。表示の名前は期限で消えた後は null)。
     *
     * @param  list<AiToolCall>  $rows
     * @return list<RunRecord>
     */
    private function records(array $rows): array
    {
        $moduleNames = array_column($this->modules->topLevelModules(), 'name', 'code');
        $records = [];

        foreach ($rows as $row) {
            $names = self::namesOf($row);

            foreach ($row->records ?? [] as $record) {
                $key = "{$record['module']}:{$record['id']}";
                $records[$key] ??= [
                    'module' => $record['module'],
                    'module_name' => $moduleNames[$record['module']] ?? null,
                    'id' => $record['id'],
                    'name' => $names[$key] ?? null,
                ];
            }
        }

        return array_values($records);
    }

    /**
     * @return array<string, string> 業務モジュールのコード:id → 表示の名前
     */
    private static function namesOf(AiToolCall $row): array
    {
        $names = [];

        foreach ($row->text->record_names ?? [] as $record) {
            $names["{$record['module']}:{$record['id']}"] = $record['name'];
        }

        return $names;
    }
}
