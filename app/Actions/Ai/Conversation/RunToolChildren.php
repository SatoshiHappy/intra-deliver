<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Api\PriorToolCall;
use App\Actions\Ai\Core\CoreToolCall;
use App\Actions\Ai\ToolCalls\GrantedTool;
use App\Actions\Ai\ToolCalls\ModelToolCall;
use App\Actions\Ai\ToolCalls\ToolCallRows;
use App\Actions\Ai\ToolCalls\ToolResultItems;
use App\Actions\Ai\ToolCalls\ToolStep;
use App\Enums\Ai\AiToolCallDecision;
use App\Enums\Ai\AiToolCallStatus;
use App\Models\Ai\AiToolCall;
use LogicException;

/**
 * 並べて動かす組の worker の側(docs の 設計/AI/エージェントの実行/道具の包みと止める.md 9.4): 呼び出しの順に行の並びの値を振って子を起こし、
 * 子の結果の項目を呼び出しの順に会話の保存へ書く。子が落ちた・時間切れの呼び出しは、道具の行が無ければ包みの中の例外の値で作り、処理中の行が
 * あれば道具の例外の値で終え、決まった失敗の文を書く。
 */
final readonly class RunToolChildren
{
    public function __construct(
        private ToolChildRunner $children,
        private ToolCallRows $rows,
        private ToolResultItems $items,
    ) {}

    /**
     * @param  non-empty-list<array{CoreToolCall, GrantedTool}>  $calls  呼び出しの順の呼び出しと探すで見つけた道具
     * @param  positive-int  $concurrency
     * @return list<ConversationItem>|null 呼び出しの順の結果の項目(会話が無ければ null)
     *
     * @throws LogicException 子が問うを返したとき(並べてよいのは読むだけの道具 = 道具と権限の 10.1)
     */
    public function run(RunToolStep $step, array $calls, int $concurrency): ?array
    {
        $toolStep = $step->toolStep();
        $prior = $step->prior();
        $inputs = [];

        [$runId, $stepId] = [$toolStep->run->id, $toolStep->step->id];

        foreach ($calls as [$call, $granted]) {
            $model = new ModelToolCall($call->callId, $call->name, $call->arguments);
            $inputs[] = ToolCallChildInput::of($runId, $stepId, $step->nextPosition(), $model, $prior, $granted);
        }

        $outputs = $this->children->run($inputs, $concurrency);
        $items = [];

        foreach ($inputs as $index => $input) {
            $output = $outputs[$index] ?? null;
            $item = $output === null
                ? $this->failed($toolStep, $step, $input)
                : $this->returned($toolStep, $step, $input, $output);

            if ($item === null) {
                return null;
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function returned(
        ToolStep $toolStep,
        RunToolStep $step,
        ToolCallChildInput $input,
        array $output,
    ): ?ConversationItem {
        $shaped = is_array($output['item'] ?? null) && is_array($output['prior'] ?? null);

        if (($output['asked'] ?? false) === true || ! $shaped) {
            throw new LogicException("並べて動かした呼び出し {$input->call->id} の子が結果の項目を返さなかった");
        }

        $item = ConversationItem::fromArray($output['item']);
        $step->passed(ToolCallChildInput::priorOf($output['prior']));

        return $toolStep->store->append($toolStep->run->id, [$item]) ? $item : null;
    }

    /**
     * 子が落ちた・時間切れの呼び出し。行が終わっていれば(結果を書いた後に子が落ちた)行はそのままにし、決まった失敗の文だけを足す。
     */
    private function failed(ToolStep $toolStep, RunToolStep $step, ToolCallChildInput $input): ?ConversationItem
    {
        $call = $input->call;
        $item = $this->items->exception($call->id, $call->name);
        $row = AiToolCall::query()->where('ai_run_step_id', $toolStep->step->id)->where('call_id', $call->id)->first();
        $decision = $row === null ? null : $row->decision;
        $decision ??= AiToolCallDecision::WrapperException;
        // 子がどこまで進んだか分からないので、形の検証と名前の直しを通った引数は無いものとする
        $step->passed(new PriorToolCall($call->id, $call->name, null, $decision));

        if ($row === null) {
            $entry = [
                'position' => $input->position,
                'call' => $call,
                'decision' => AiToolCallDecision::WrapperException,
                'status' => AiToolCallStatus::ToolError,
                'reason' => null,
                'item' => $item,
                'text' => null,
            ];

            return $this->rows->finished($toolStep, [$entry]) === null ? null : $item;
        }

        $written = $row->status === AiToolCallStatus::Running
            ? $this->rows->completed($toolStep, $row, AiToolCallStatus::ToolError, null, [], $item)
            : $toolStep->store->append($toolStep->run->id, [$item]);

        return $written ? $item : null;
    }
}
