<?php

namespace Tests\Feature\Ai\Conversation;

use App\Actions\Ai\Conversation\LoopRequestOf;
use App\Actions\Ai\Conversation\RunCreatedFiles;
use App\Actions\Ai\Conversation\StartConversationRun;
use App\Actions\Ai\Conversation\StepTargetsOf;
use App\Actions\Ai\Core\AddedTextValues;
use App\Actions\Ai\Core\LoopReceivers;
use App\Actions\Ai\Core\LoopRequest;
use App\Actions\Ai\ToolCalls\GrantedTools;
use App\Enums\Ai\AiConversationAnswer;
use App\Enums\Ai\AiRunStatus;
use App\Enums\Ai\AiRunStepSendKind;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStep;
use Tests\Feature\Ai\RecordingConversationKind;
use Tests\Feature\Ai\UsesTestConversationKinds;
use Tests\Support\TransactionalTestCase;
use Tests\Unit\Actions\Ai\Core\Support\FakeClock;
use Tests\Unit\Actions\Ai\Core\Support\FakeLoopTexts;
use Tests\Unit\Actions\Ai\Core\Support\FakeReceivers;
use Tests\Unit\Actions\Ai\Core\Support\FakeTools;

/**
 * 会話のジョブの 5 で中核に渡す物(docs の 設計/AI/エージェントの実行/README.md 9.1 の口の約束、写した値 = 設計/AI/実行の記録.md 2.1)。
 * 種類の設定 = 回数の上限 10・会話の用途の時間の上限 100 秒・出力の上限 1,000。
 */
final class LoopRequestOfTest extends TransactionalTestCase
{
    use UsesTestConversationKinds;

    private const VISITOR = '0193c7a2-1b2c-7d3e-8f40-123456789abc';

    /** @var array<int, AiRunStatus> */
    private array $statuses = [];

    public function test_the_request_uses_the_copied_values(): void
    {
        $this->registerTestConversationKind();
        $run = AiRun::factory()->conversation()->create([
            'kind' => RecordingConversationKind::CODE,
            'visitor_id' => self::VISITOR,
        ]);
        $request = $this->requestOf($run);

        $model = $request->target->model;
        $this->assertSame(['claude-sonnet-5-5', 1000000, 1000, true], [
            $model->id,
            $model->contextWindow,
            $model->maxOutputTokens,
            $model->usesThinking,
        ]);
        $this->assertSame([100, 10, true], [$request->target->timeoutSeconds, $request->maxTurns, $request->stream]);
        $metadata = $request->providerOptions['turn']['metadata'] ?? null;
        $this->assertSame(['user_id' => self::VISITOR], $metadata);
        $this->assertSame($request->providerOptions['turn'], $request->providerOptions['compaction']);
        $this->assertSame([], $request->tools);
        $this->assertSame(0, $request->priorTurns);
    }

    /**
     * 前の実行が確認待ちで終わっている間さかのぼり、着いた実行を含めて成功した回の答えの送信を引き継ぐ(9.7)。答えの実行も、確認待ちの
     * 実行に文を送った実行も引き継ぐ。前の実行が確認待ちでなければ 0。失敗した試し・続き・鎖の外の実行は数えない。
     */
    public function test_a_run_after_an_awaiting_run_inherits_the_succeeded_turns_of_its_chain(): void
    {
        $this->registerTestConversationKind();

        $this->assertSame(4, $this->requestOf($this->awaitingChain(true))->priorTurns);
        $this->assertSame(3, $this->requestOf($this->awaitingChain(false))->priorTurns);
        $ended = $this->chained($this->awaitingChain(true), null, AiRunStatus::Succeeded, []);
        $this->assertSame(0, $this->requestOf($this->chained($ended, null, AiRunStatus::Succeeded, []))->priorTurns);
    }

    /**
     * 成功した実行(回 1)→ 文の実行(確認待ち・成功 2・失敗 1・続き 1)→ 答えの実行(確認待ち・回 1)→ 確認待ちに文を送った実行(確認待ち・回 1)
     * →($withAnswer なら)答えの実行。最後の実行を返す。
     */
    private function awaitingChain(bool $withAnswer): AiRun
    {
        $root = $this->chained(null, null, AiRunStatus::Succeeded, ['turn']);
        $steps = ['turn', 'turn', 'failed_turn', 'continuation'];
        $text = $this->chained($root, null, AiRunStatus::AwaitingConfirmation, $steps);
        $answered = $this->chained($text, AiConversationAnswer::Approve, AiRunStatus::AwaitingConfirmation, ['turn']);
        $typed = $this->chained($answered, null, AiRunStatus::AwaitingConfirmation, ['turn']);

        return $withAnswer ? $this->chained($typed, AiConversationAnswer::Reject, AiRunStatus::Succeeded, []) : $typed;
    }

    /**
     * 前の実行につないだ会話の型の実行とそのステップ(turn = 成功した回の答え・failed_turn = 使用量の無い失敗の回の答え・continuation = 成功した続き)。
     * 状態は始める前に {@see self::endChain()} で書く(作る時は待ちの実行)。
     *
     * @param  list<'turn'|'failed_turn'|'continuation'>  $steps
     */
    private function chained(?AiRun $previous, ?AiConversationAnswer $answer, AiRunStatus $status, array $steps): AiRun
    {
        $run = AiRun::factory()->conversation()->create([
            'kind' => RecordingConversationKind::CODE,
            'previous_run_id' => $previous?->id,
            'answer' => $answer,
            'wants_title' => false,
        ]);

        foreach ($steps as $step) {
            $factory = AiRunStep::factory()->state(['ai_run_id' => $run->id]);
            match ($step) {
                'turn' => $factory->succeeded()->create(),
                'failed_turn' => $factory->failed()->create(),
                'continuation' => $factory->sendKind(AiRunStepSendKind::Continuation)->succeeded()->create(),
            };
        }

        $this->statuses[$run->id] = $status;

        return $run;
    }

    /** 始める実行のほかの鎖の実行に状態を書く(作る時は待ちの実行) */
    private function endChain(int $startingId): void
    {
        foreach ($this->statuses as $id => $status) {
            if ($id !== $startingId) {
                $ended = ['status' => $status, 'started_at' => now(), 'finished_at' => now()];
                AiRun::query()->whereKey($id)->update($ended);
            }
        }

        $this->statuses = [];
    }

    private function requestOf(AiRun $run): LoopRequest
    {
        $this->endChain($run->id);
        app(StartConversationRun::class)->execute($run->id);
        $receivers = new FakeReceivers;
        $loopReceivers = new LoopReceivers(
            new FakeTools,
            $receivers,
            $receivers,
            new FakeClock,
            new FakeLoopTexts,
            new RunCreatedFiles,
        );
        $fresh = AiRun::query()->findOrFail($run->id);

        return app(LoopRequestOf::class)->of(
            $fresh,
            app(StepTargetsOf::class)->targets($fresh)->conversation,
            [],
            new GrantedTools([], []),
            new AddedTextValues('2026-11-19'),
            $loopReceivers,
        );
    }
}
