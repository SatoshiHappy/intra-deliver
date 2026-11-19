<?php

namespace Tests\Feature\Ai\Conversation;

use App\Actions\Ai\Api\ConversationEnded;
use App\Actions\Ai\Api\ConversationFailed;
use App\Actions\Ai\Api\ConversationKind;
use App\Actions\Ai\Api\ConversationStore;
use App\Actions\Ai\Conversation\CompleteConversationRun;
use App\Actions\Ai\Core\LoopEnding;
use App\Actions\Ai\Core\LoopFailure;
use App\Actions\Ai\Core\LoopStop;
use App\Enums\Ai\AiRunStatus;
use App\Enums\Ai\AiRunStopReason;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStep;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Ai\FakeConversationStore;
use Tests\Feature\Ai\RecordingConversationKind;
use Tests\Feature\Ai\UsesTestConversationKinds;
use Tests\Support\TransactionalTestCase;

/**
 * 会話のジョブの 6 の書き方(docs の 設計/AI/エージェントの実行/README.md 9.1 のジョブの順、受け手 = 設計/AI/エージェントの実行/口と契約.md 9.13)。
 */
final class CompleteConversationRunTest extends TransactionalTestCase
{
    use UsesTestConversationKinds;

    private AiRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestConversationKind();
        $this->run = AiRun::factory()->conversation()->running()->create(['kind' => RecordingConversationKind::CODE]);
    }

    /**
     * @return array<string, array{LoopEnding, AiRunStatus, AiRunStopReason|null, string|null}>
     */
    public static function endings(): array
    {
        $stopped = static fn (LoopStop $stop, AiRunStopReason $reason, ?string $period = null): array => [
            LoopEnding::stopped($stop, $period),
            AiRunStatus::Stopped,
            $reason,
            $period,
        ];

        return [
            '成功' => [LoopEnding::succeeded(), AiRunStatus::Succeeded, null, null],
            '確認待ち' => [LoopEnding::awaitingConfirmation(['c1']), AiRunStatus::AwaitingConfirmation, null, null],
            '止めた(利用者)' => $stopped(LoopStop::User, AiRunStopReason::User),
            '止めた(時間)' => $stopped(LoopStop::TimeLimit, AiRunStopReason::TimeLimit),
            '止めた(回数)' => $stopped(LoopStop::TurnLimit, AiRunStopReason::TurnLimit),
            '止めた(長さ)' => $stopped(LoopStop::Length, AiRunStopReason::Length),
            '止めた(費用)' => $stopped(LoopStop::CostLimit, AiRunStopReason::Budget, 'day'),
        ];
    }

    #[DataProvider('endings')]
    public function test_the_ending_is_written(
        LoopEnding $ending,
        AiRunStatus $status,
        ?AiRunStopReason $reason,
        ?string $period,
    ): void {
        AiRunStep::factory()->succeeded('0.002000')->create(['ai_run_id' => $this->run->id]);
        $complete = app(CompleteConversationRun::class);

        $written = $complete->execute($this->run->id, $this->conversationKind, $ending, '題');

        $run = AiRun::query()->findOrFail($this->run->id);
        $this->assertTrue($written);
        $written = [$run->status, $run->stop_reason, $run->stop_budget_period];
        $this->assertSame([$status, $reason, $period], $written);
        $this->assertSame('0.002000', $run->step_cost_sum_usd);
        $this->assertNotNull($run->finished_at);
        $ended = $this->conversationKind->completed[0] ?? null;
        $this->assertSame([$status, $reason, '題'], [$ended?->status, $ended?->stopReason, $ended?->title]);
    }

    public function test_a_failure_is_not_written_here(): void
    {
        $this->expectException(LogicException::class);

        app(CompleteConversationRun::class)->execute(
            $this->run->id,
            $this->conversationKind,
            LoopEnding::failed(LoopFailure::Provider),
            null,
        );
    }

    /** ほかの経路が先に終えていたら、受け手を呼ばず何も書かない */
    public function test_a_run_ended_elsewhere_is_left_alone(): void
    {
        $failed = AiRun::factory()->conversation()->failed()->create(['kind' => RecordingConversationKind::CODE]);

        $written = app(CompleteConversationRun::class)->execute(
            $failed->id,
            $this->conversationKind,
            LoopEnding::succeeded(),
            null,
        );

        $this->assertFalse($written);
        $this->assertSame([], $this->conversationKind->completed);
    }

    /** 受け手が投げたら何も書かずに投げ直す(実行は処理中のまま。失敗の書き方は呼び手) */
    public function test_a_receiver_exception_writes_nothing(): void
    {
        $kind = new class implements ConversationKind
        {
            public function code(): string
            {
                return RecordingConversationKind::CODE;
            }

            public function store(): ConversationStore
            {
                return new FakeConversationStore;
            }

            public function completed(ConversationEnded $run): void
            {
                throw new RuntimeException('受け手の誤り');
            }

            public function failed(ConversationFailed $run): void {}
        };

        try {
            app(CompleteConversationRun::class)->execute($this->run->id, $kind, LoopEnding::succeeded(), null);
            $this->fail('投げ直さなかった');
        } catch (RuntimeException) {
            $this->assertSame(AiRunStatus::Running, AiRun::query()->findOrFail($this->run->id)->status);
        }
    }
}
