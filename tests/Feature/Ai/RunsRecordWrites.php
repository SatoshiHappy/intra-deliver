<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\ToolCalls\ModelToolCall;
use App\Actions\Ai\ToolCalls\ToolCallWrapper;
use App\Actions\Ai\ToolCalls\ToolCallWrites;
use App\Actions\Ai\ToolCalls\ToolGrants;
use App\Actions\Ai\ToolCalls\ToolStep;
use App\Actions\Ai\ToolCalls\ToolStepOutcome;
use App\Actions\Ai\ToolCalls\ToolSubjects;
use App\Enums\Ai\AiConversationAnswer;
use App\Enums\Ai\AiRunStopReason;
use App\Enums\Ai\AiToolCallAnswer;
use App\Enums\Ai\AiToolCallStatus;
use App\Enums\Ai\AiToolWriteDenial;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStep;
use App\Models\Ai\AiToolCall;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * 書く道具の問うと答えの実行の書く時の部品を通す(RunsRecordTools の実行と会話の保存を使う)。
 */
trait RunsRecordWrites
{
    private ?AiRun $answerRun = null;

    /** 答えの実行の書く時のステップ */
    private ?ToolStep $answerStep = null;

    /** @var (Closure(): void)|null 答えの実行を作った後、書く前に呼ぶ関数 */
    private ?Closure $beforeAnswer = null;

    /** 問うを通した回数(2 回目からの呼び出しの id を実行の中で重ねないため) */
    private int $asks = 0;

    /**
     * 呼び出しを 1 つのステップで包みに通し、問うになった道具の行を返す(呼び出しの順)。
     *
     * @param  list<array{string, array<string, mixed>}>  $calls  道具の名前と引数
     * @return list<AiToolCall>
     */
    private function ask(array $calls): array
    {
        $this->step = AiRunStep::factory()->create(['ai_run_id' => $this->run->id]);
        $step = new ToolStep($this->run, $this->step, $this->granted, $this->store);
        $models = array_map(
            fn (array $call, int $index): ModelToolCall => new ModelToolCall(
                ($this->asks === 0 ? 'c' : "s{$this->asks}c").($index + 1),
                $call[0],
                $call[1],
            ),
            $calls,
            array_keys($calls),
        );
        app(ToolCallWrapper::class)->run($step, $models, fn (): ?AiRunStopReason => null);
        $this->asks++;

        $rows = AiToolCall::query()->where('ai_run_step_id', $this->step->id)->orderBy('position')->get();

        return array_values($rows->all());
    }

    /**
     * 答えの実行で承認した件を書く(定義は今の定義を読み直す = 答えの実行の始め)。
     *
     * @param  list<AiToolCall>  $rows
     * @param  (Closure(): ?AiRunStopReason)|null  $stopped
     */
    private function answer(array $rows, ?Closure $stopped = null): ToolStepOutcome
    {
        $definition = $this->run->agentDefinition()->sole();
        $this->answerRun = AiRun::factory()->conversation()->create([
            'previous_run_id' => $this->run->id,
            'answer' => AiConversationAnswer::Approve,
            'requester_user_id' => $this->run->requester_user_id,
            'agent_definition_id' => $definition->id,
        ]);
        $conversationId = (int) DB::table('test_conversation_runs')
            ->where('ai_run_id', $this->run->id)
            ->value('conversation_id');
        $this->store->continueConversation($conversationId, $this->answerRun->id);
        $granted = app(ToolGrants::class)->grant($definition, app(ToolSubjects::class)->for($this->answerRun));
        $answerStep = AiRunStep::factory()->create(['ai_run_id' => $this->answerRun->id]);
        $step = new ToolStep($this->answerRun, $answerStep, $granted, $this->store);
        $this->answerStep = $step;
        $fresh = array_map(fn (AiToolCall $row): AiToolCall => $row->refresh(), $rows);

        $stopped ??= fn (): ?AiRunStopReason => null;
        $this->beforeAnswer === null ? null : ($this->beforeAnswer)();

        return app(ToolCallWrites::class)->write($step, $definition, $fresh, $stopped);
    }

    private function assertRow(AiToolCall $row, AiToolCallStatus $status, ?AiToolWriteDenial $denial): void
    {
        $row->refresh();
        $this->assertSame([$status, $denial], [$row->status, $row->write_denial_reason]);
        $this->assertSame([AiToolCallAnswer::Approve, $this->answerRun?->id], [$row->answer, $row->answer_run_id]);
    }

    private function item(ToolStepOutcome $outcome, string $callId): ConversationItem
    {
        foreach ($outcome->results as $result) {
            if ($result['callId'] === $callId) {
                return $result['item'];
            }
        }

        $this->fail("呼び出し {$callId} の結果が無い");
    }
}
