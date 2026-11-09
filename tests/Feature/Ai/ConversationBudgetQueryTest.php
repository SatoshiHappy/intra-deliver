<?php

namespace Tests\Feature\Ai;

use App\Enums\Ai\AiRunStatus;
use App\Enums\Ai\AiRunStopReason;
use App\Models\Ai\AiRun;
use App\Queries\Ai\Api\RunBudgetQuery;
use App\Queries\Ai\KindSettings;
use App\Queries\Ai\RunEstimates;
use Carbon\CarbonImmutable;
use Tests\Support\TransactionalTestCase;

/**
 * 予算の区分の状態の会話の型の数え方(docs の 設計/AI/公開の口.md 3 節の表の「予算の区分の状態」、設計/AI/実行と費用/接続先と費用.md 5.3 の
 * 「1 依頼の見積もりの上限」の「依頼の口の先の断りと予算の区分の状態には足さない」): 会話の型の種類を渡すと使った額だけで見る(会話の型は 0)。
 * 種類を渡さない区分の状態は、会話の型の種類を除いた最大で数える。種類を渡すと、区分の最大でなくその種類の 1 依頼の見積もりの上限で数える。
 * 額の刻みは 100 万分の 1 ドル(表の numeric(12,6))。既定の区分の日の上限を 1 ドルにし、月と累計は上限なし。
 */
final class ConversationBudgetQueryTest extends TransactionalTestCase
{
    use UsesTestConversationKinds;

    /** 議事録(meeting.minutes)の 1 依頼の見積もりの上限(PromptKindsTest の今の設定の値) */
    private const MINUTES_CEILING = 0.161;

    /** 区分に足す、議事録より大きい文章の種類(出力の上限 100 万 × 0.50 + 入力 (1,000 × 2 + 2,000) × 0.10 = 0.5004 ドル) */
    private const LARGE_CEILING = 0.5004;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestConversationKind();
        config([
            'ai_runs.budget.default' => ['daily_usd' => 1, 'monthly_usd' => null, 'total_usd' => null],
            'ai_runs.budget.transcription' => ['daily_usd' => null, 'monthly_usd' => null, 'total_usd' => null],
        ]);
    }

    public function test_the_ceiling_of_a_conversation_kind_is_zero_and_it_is_left_out_of_the_budget(): void
    {
        $estimates = app(RunEstimates::class);
        $kind = app(KindSettings::class)->settings(RecordingConversationKind::CODE);

        $this->assertNotNull($kind);
        $this->assertSame('0.000000', $estimates->ceilingOfKind($kind));
        $this->assertSame('0.161000', $estimates->ceilingOf('default'));
    }

    /**
     * 会話の型の種類を渡すと、使った額が上限より 1 刻み少ない・上限と同じでは無し、1 刻み多いと超えた期間を返す
     * (使った額が「上限 − 1 依頼の見積もりの上限」をとうに超えていても、会話の型は足さないので無し)。
     */
    public function test_a_conversation_kind_is_counted_by_the_spent_amount_only(): void
    {
        $this->spend('0.999999');
        $this->assertNull($this->ofKind(RecordingConversationKind::CODE));
        $this->assertSame(RunBudgetQuery::DAY, $this->ofKind('meeting.minutes'));

        $this->spend('0.000001');
        $this->assertNull($this->ofKind(RecordingConversationKind::CODE));

        $this->spend('0.000001');
        $this->assertSame(RunBudgetQuery::DAY, $this->ofKind(RecordingConversationKind::CODE));
    }

    /** 種類を渡さない区分の状態は、会話の型の種類を除いた最大(今の設定では議事録)で数える */
    public function test_the_state_of_the_budget_counts_the_largest_kind_other_than_the_conversation_kinds(): void
    {
        $this->spend(self::usd(1 - self::MINUTES_CEILING));
        $this->assertNull($this->ofBudget());

        $this->spend('0.000001');
        $this->assertSame(RunBudgetQuery::DAY, $this->ofBudget());
    }

    /** 議事録の種類を渡すと、区分に議事録より大きい種類があっても、議事録の 1 依頼の見積もりの上限で数える */
    public function test_a_prompt_kind_is_counted_by_its_own_ceiling_not_the_largest_of_its_budget(): void
    {
        config(['ai_runs.kinds' => [...(array) config('ai_runs.kinds'), 'test.large' => [
            'type' => 'prompt',
            'provider' => 'anthropic',
            'model' => 'claude-haiku-5-5',
            'budget' => 'default',
            'timeout_seconds' => 100,
            'max_output_tokens' => 1_000_000,
            'max_input_chars' => 1000,
        ]]]);
        $this->assertSame(self::usd(self::LARGE_CEILING), app(RunEstimates::class)->ceilingOf('default'));

        $this->spend(self::usd(1 - self::MINUTES_CEILING));
        $this->assertNull($this->ofKind('meeting.minutes'));
        $this->assertSame(RunBudgetQuery::DAY, $this->ofKind('test.large'));
        $this->assertSame(RunBudgetQuery::DAY, $this->ofBudget());

        $this->spend('0.000001');
        $this->assertSame(RunBudgetQuery::DAY, $this->ofKind('meeting.minutes'));
    }

    /**
     * 会話の型の実行の行は使った額に入らない(会話の型はステップの行から数える): 文章の行と混ざる日は文章の行の分だけで数える。
     * 会話の型の行は費用と見積もりを持たないので、外す前から結果は同じ(守る結果の確かめ。外したことの証は繰り越しと退避から戻す道具のテスト)。
     */
    public function test_conversation_runs_add_nothing_to_the_spent_amount_on_a_day_with_prompt_runs(): void
    {
        $this->spend('1.000000');
        $this->conversationRuns();

        $this->assertNull($this->ofKind(RecordingConversationKind::CODE));

        $this->spend('0.000001');
        $this->assertSame(RunBudgetQuery::DAY, $this->ofKind(RecordingConversationKind::CODE));
    }

    /** 会話の型の行だけの日は、使った額が 0(区分の状態は、議事録の 1 依頼の見積もりの上限 = 日の上限でも超えない)。守る結果の確かめ */
    public function test_conversation_runs_alone_add_nothing_to_the_spent_amount(): void
    {
        config(['ai_runs.budget.default.daily_usd' => self::MINUTES_CEILING]);
        $this->conversationRuns();

        $this->assertNull($this->ofBudget());
        $this->assertNull($this->ofKind(RecordingConversationKind::CODE));
    }

    private function ofKind(string $kindCode): ?string
    {
        return app(RunBudgetQuery::class)->exceededPeriodOfKind($kindCode, CarbonImmutable::now());
    }

    private function ofBudget(): ?string
    {
        return app(RunBudgetQuery::class)->exceededPeriod('default', CarbonImmutable::now());
    }

    /** 既定の区分に、今日の会話の型の実行の行(待ち・処理中・失敗・止めた・確認待ち)を足す */
    private function conversationRuns(): void
    {
        $at = ['kind' => RecordingConversationKind::CODE, 'budget' => 'default', 'created_at' => now()];
        $finished = ['started_at' => now(), 'finished_at' => now()];
        $conversation = AiRun::factory()->conversation();

        $conversation->create($at);
        $conversation->running()->create($at);
        $conversation->failed()->create($at);
        $stopped = ['status' => AiRunStatus::Stopped, 'stop_reason' => AiRunStopReason::User];
        $conversation->create([...$stopped, ...$finished, ...$at]);
        $conversation->create(['status' => AiRunStatus::AwaitingConfirmation, ...$finished, ...$at]);
    }

    /** 既定の区分に、使用量の分かった文章の実行を足す */
    private function spend(string $costUsd): void
    {
        $now = CarbonImmutable::now()->utc();
        AiRun::factory()->succeeded($costUsd)->create(['budget' => 'default', 'created_at' => $now]);
    }

    private static function usd(float $amount): string
    {
        return RunEstimates::usd($amount);
    }
}
