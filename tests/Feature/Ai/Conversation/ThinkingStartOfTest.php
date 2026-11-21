<?php

namespace Tests\Feature\Ai\Conversation;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Conversation\ThinkingStartOf;
use App\Actions\Ai\Core\ThinkingStart;
use App\Enums\Ai\AiRunStepSendKind;
use App\Enums\Ai\AiRunStepStatus;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStep;
use App\Models\Ai\AiToolCall;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 思考の文脈の管理の始めの値をステップの行から引く(docs の 設計/AI/会話の中核.md 13.2): 1 時間の境(ちょうど・1 秒多い)・境目の前後・
 * 比べる送信の種別(回の答えと続きの始めの試しだけ)・完了に数える行(成功だけ。題とファイルを作るの中を含む)・実行の鎖の中だけ。
 * 時刻は 2026-11-18 の UTC の時分秒。
 */
final class ThinkingStartOfTest extends TransactionalTestCase
{
    private AiRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->run = AiRun::factory()->conversation()->running()->create();
    }

    public function test_a_conversation_without_steps_starts_lowered_without_a_completion(): void
    {
        $start = $this->start();

        $this->assertSame([false, null], [$start->idled, $start->lastCompletedAt]);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function gaps(): array
    {
        return [
            '1 時間ちょうど' => ['01:01:00', false],
            '1 時間と 1 秒' => ['01:01:01', true],
            '1 時間と 0.6 秒(行は秒の端数を書かずに持つ)' => ['01:01:00.6', false],
        ];
    }

    /** 題の成功(0:00〜0:01)の後の回の答え */
    #[DataProvider('gaps')]
    public function test_a_turn_compares_the_gap_from_the_previous_completion(string $turnAt, bool $idled): void
    {
        $this->step(AiRunStepSendKind::Title, '00:00:00', '00:01:00');
        $this->step(AiRunStepSendKind::Turn, $turnAt, '01:30:00');

        $start = $this->start();

        $this->assertSame([$idled, '01:30:00'], [$start->idled, $start->lastCompletedAt?->format('H:i:s')]);
    }

    /** 境目の項目が指すステップの行より前の空きは数えず、後ろの空きは数える */
    public function test_only_sends_after_the_boundary_step_are_compared(): void
    {
        $this->step(AiRunStepSendKind::Turn, '00:00:00', '00:01:00');
        $this->step(AiRunStepSendKind::Turn, '03:00:00', '03:01:00');
        $compaction = $this->step(AiRunStepSendKind::Compaction, '03:02:00', '03:03:00');

        $this->assertFalse($this->start($compaction)->idled);

        $this->step(AiRunStepSendKind::Turn, '05:00:00', '05:01:00');
        $this->assertTrue($this->start($compaction)->idled);
    }

    /** 比べるのは回の答えと続きの始めの試しだけ(送り直しの試し・題・要約は比べない) */
    public function test_only_first_attempts_of_turns_and_continuations_are_compared(): void
    {
        $first = $this->step(AiRunStepSendKind::Turn, '00:00:00', '00:01:00', AiRunStepStatus::Failed);
        AiRunStep::factory()->retryOf($first)->succeeded()->create(self::times('02:00:00', '02:01:00'));
        $this->step(AiRunStepSendKind::Title, '04:00:00', '04:01:00');
        $this->step(AiRunStepSendKind::Compaction, '06:00:00', '06:01:00');
        $this->assertFalse($this->start()->idled);

        $this->step(AiRunStepSendKind::Continuation, '08:00:00', '08:01:00');
        $this->assertTrue($this->start()->idled);
    }

    /** 完了に数えるのは成功した行(ファイルを作るの中の呼び出しを含む)で、失敗の終わりは数えない */
    public function test_completions_are_the_succeeded_rows_including_inside_tool_calls(): void
    {
        $this->step(AiRunStepSendKind::Turn, '00:00:00', '00:01:00');
        $this->step(AiRunStepSendKind::Title, '00:30:00', '00:59:00', AiRunStepStatus::Failed);
        $this->step(AiRunStepSendKind::Turn, '01:01:01', '01:02:00');
        $this->assertTrue($this->start()->idled);

        $this->setUpRun();
        $turn = $this->step(AiRunStepSendKind::Turn, '00:00:00', '00:01:00');
        $toolCall = AiToolCall::factory()->create(['ai_run_id' => $this->run->id, 'ai_run_step_id' => $turn->id]);
        AiRunStep::factory()->insideToolCall($toolCall)->succeeded()->create(self::times('00:40:00', '00:50:00'));
        $this->step(AiRunStepSendKind::Turn, '01:01:01', '01:02:00');
        $this->assertFalse($this->start()->idled);
    }

    /** 鎖の前の実行のステップを数え、ほかの会話のステップは数えない */
    public function test_the_steps_of_the_run_chain_only(): void
    {
        $this->step(AiRunStepSendKind::Turn, '00:00:00', '00:01:00');
        $this->step(AiRunStepSendKind::Turn, '03:00:00', '03:01:00');
        $previous = $this->run;
        $this->run = AiRun::factory()->conversation()->running()->create(['previous_run_id' => $previous->id]);
        $other = AiRun::factory()->conversation()->running()->create();
        AiRunStep::factory()->succeeded()->create(['ai_run_id' => $other->id, ...self::times('09:00:00', '09:30:00')]);

        $start = $this->start();

        $this->assertSame([true, '03:01:00'], [$start->idled, $start->lastCompletedAt?->format('H:i:s')]);
    }

    private function setUpRun(): void
    {
        $this->run = AiRun::factory()->conversation()->running()->create();
    }

    private function start(?AiRunStep $boundary = null): ThinkingStart
    {
        $history = $boundary === null ? [] : [ConversationItem::compactBoundary(1, '2026-11-18', $boundary->id)];

        return app(ThinkingStartOf::class)->of($this->run->id, $history);
    }

    private function step(
        AiRunStepSendKind $kind,
        string $start,
        string $end,
        AiRunStepStatus $status = AiRunStepStatus::Succeeded,
    ): AiRunStep {
        $factory = AiRunStep::factory()->sendKind($kind);
        $factory = $status === AiRunStepStatus::Succeeded ? $factory->succeeded() : $factory->failed();

        return $factory->create(['ai_run_id' => $this->run->id, ...self::times($start, $end)]);
    }

    /**
     * @return array{started_at: CarbonImmutable, finished_at: CarbonImmutable}
     */
    private static function times(string $start, string $end): array
    {
        return [
            'started_at' => CarbonImmutable::parse("2026-11-18 {$start}+00"),
            'finished_at' => CarbonImmutable::parse("2026-11-18 {$end}+00"),
        ];
    }
}
