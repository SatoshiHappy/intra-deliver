<?php

namespace Database\Factories\Ai;

use App\Enums\Ai\AiConversationAnswer;
use App\Enums\Ai\AiToolCallAnswer;
use App\Enums\Ai\AiToolCallDecision;
use App\Enums\Ai\AiToolCallStatus;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStep;
use App\Models\Ai\AiToolCall;
use App\Models\Ai\AiToolCallText;
use Illuminate\Database\Eloquent\Factories\Factory;
use LogicException;

/**
 * 既定は新しく作るステップ(会話の型の実行の回の答え)の中の、許すと判定して済みになった呼び出し(順はそのステップの次の順)。
 * 状態ごとのメソッドは判定と答えの組(docs の 設計/AI/実行の記録.md 2.4 の道具の行の状態の表)ごとに 1 つ。原文の行は {@see self::withText()}。
 *
 * @extends Factory<AiToolCall>
 */
class AiToolCallFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_run_step_id' => AiRunStep::factory(),
            'ai_run_id' => fn (array $attributes): int => (int) AiRunStep::query()
                ->whereKey($attributes['ai_run_step_id'])->value('ai_run_id'),
            'position' => fn (array $attributes): int => self::nextPosition((int) $attributes['ai_run_step_id']),
            'call_id' => fn (): string => 'toolu_'.bin2hex(random_bytes(8)),
            'tool_name' => 'search_records',
            'decision' => AiToolCallDecision::Allow,
            'status' => AiToolCallStatus::Done,
            'records' => [],
            'finished_at' => now(),
        ];
    }

    /** ステップの中の呼び出し(同じ実行) */
    public function inStep(AiRunStep $step): static
    {
        return $this->state(fn (): array => ['ai_run_step_id' => $step->id, 'ai_run_id' => $step->ai_run_id]);
    }

    /** 判定の前に止めた(届かない。取り消し) */
    public function notReached(): static
    {
        return $this->withStatus(null, AiToolCallStatus::Cancelled);
    }

    /** 包みの結果(道具の誤り) */
    public function wrapperError(AiToolCallDecision $decision = AiToolCallDecision::InvalidInput): static
    {
        return $this->withStatus($decision, AiToolCallStatus::ToolError);
    }

    /** 断ると判定した(断られた) */
    public function denied(string $reason = 'rule'): static
    {
        return $this->withStatus(AiToolCallDecision::Deny, AiToolCallStatus::Rejected)
            ->state(fn (): array => ['decision_reason' => $reason]);
    }

    /** 許すと判定した */
    public function allowed(AiToolCallStatus $status = AiToolCallStatus::Done): static
    {
        return $this->withStatus(AiToolCallDecision::Allow, $status);
    }

    /** 問うと判定し、まだ答えが無い(確認の内容の版を持つ) */
    public function asked(AiToolCallStatus $status = AiToolCallStatus::AwaitingConfirmation): static
    {
        return $this->withStatus(AiToolCallDecision::Ask, $status)
            ->state(fn (): array => ['confirmation_version' => 1]);
    }

    /**
     * 問うと判定し、答えがある。答えを書いた実行を渡さなければ、この行の実行に続く実行を使う(無ければ作る。実行・やめるは答えの実行、
     * 答えずに次の文は文の実行)。
     */
    public function answered(
        AiToolCallAnswer $answer,
        ?AiToolCallStatus $status = null,
        ?AiRun $answerRun = null,
    ): static {
        $status ??= $answer === AiToolCallAnswer::Approve ? AiToolCallStatus::Done : AiToolCallStatus::Rejected;

        return $this->asked($status)->state(fn (): array => [
            'answer' => $answer,
            'answer_run_id' => fn (array $attributes): int => $answerRun->id
                ?? self::followingRun((int) $attributes['ai_run_id'], $answer)->id,
        ]);
    }

    /** 引数・結果・確認の内容・レコードの表示の名前の行も作る */
    public function withText(): static
    {
        return $this->has(AiToolCallText::factory(), 'text');
    }

    /** 判定と状態(終わりの時刻は処理中・確認待ちだけ無い) */
    private function withStatus(?AiToolCallDecision $decision, AiToolCallStatus $status): static
    {
        $open = [AiToolCallStatus::Running, AiToolCallStatus::AwaitingConfirmation];

        return $this->state(fn (): array => [
            'decision' => $decision,
            'status' => $status,
            'finished_at' => in_array($status, $open, true) ? null : now(),
        ]);
    }

    /** ステップの中の次の順 */
    private static function nextPosition(int $stepId): int
    {
        return (int) AiToolCall::query()->where('ai_run_step_id', $stepId)->max('position') + 1;
    }

    /**
     * 実行に続く会話の型の実行(1 つの実行に続く実行は 1 つなので、既にあればそれを使う)。
     *
     * @throws LogicException 既にある続く実行の答えが渡した答えと違うとき(テストの作りの誤り)
     */
    private static function followingRun(int $runId, AiToolCallAnswer $answer): AiRun
    {
        $runAnswer = AiConversationAnswer::tryFrom($answer->value);
        $following = AiRun::query()->where('previous_run_id', $runId)->first();

        if ($following !== null && $following->answer !== $runAnswer) {
            throw new LogicException("実行 {$runId} に続く実行 {$following->id} の答えが、渡した答え {$answer->value} と違う");
        }

        return $following ?? AiRun::factory()->conversation()->create([
            'previous_run_id' => $runId,
            'answer' => $runAnswer,
        ]);
    }
}
