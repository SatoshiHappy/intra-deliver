<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\PromptKind;
use App\Actions\Ai\Api\RequestPrompt;
use App\Actions\Ai\PromptKinds;
use App\Queries\Ai\AiSettings;
use App\Queries\Ai\RunEstimates;
use Tests\Support\TransactionalTestCase;

/**
 * 種類の登録と設定の食い違いを起動のテストで落とす(docs の 設計/AI/公開の口.md 3.1、実行と費用.md 4.1・5 節): 起動(AppServiceProvider)の登録と
 * config/ai_runs.php は食い違わない。同じコードの実装が 2 つ・設定に無いコードの実装・単価が無い種類・予算の区分が設定に無い種類・
 * ジョブの時間が retry_after 以上の種類・区分の守る条件(頻度の制限の回数 × 見積もりの上限の最大 < 日の上限。実行と費用.md 5.2)を破る設定は食い違いとして出る。
 */
final class PromptKindsTest extends TransactionalTestCase
{
    public function test_the_registered_kinds_and_the_settings_agree(): void
    {
        $this->assertSame([], app(PromptKinds::class)->problems());
    }

    /**
     * 今の設定の値で守る条件が成り立つ(1 日の回数 × 区分の見積もりの上限の最大 < 日の上限。
     * 見積もりの上限 = 入力の上限の文字数と出力の上限で見積もった額。default は抜き出しの約 0.284 ドルが最大 = 10 × 0.284 = 2.84 < 5)。
     */
    public function test_the_rate_limit_cannot_use_up_the_daily_budget_with_the_current_settings(): void
    {
        $ceiling = app(RunEstimates::class)->ceilingOf('default');
        $daily = app(AiSettings::class)->budget('default', 'daily_usd');

        $this->assertSame('0.284000', $ceiling);
        $this->assertLessThan($daily, RequestPrompt::REQUESTS_PER_DAY * (float) $ceiling);
    }

    public function test_detects_a_daily_limit_the_rate_limit_can_use_up(): void
    {
        $this->configureKinds(['test.big' => [...$this->kindSettings(), 'max_output_tokens' => 1_000_000]]);
        $problem = '予算の区分 default の守る条件を破る: 1 日の回数 10 × 見積もりの上限の最大が日の上限 5.000000 ドル以上';

        $this->assertSame([$problem], app(PromptKinds::class)->problems());

        config(['ai_runs.budget.default.daily_usd' => null]);

        $this->assertSame([], app(PromptKinds::class)->problems());
    }

    public function test_detects_a_kind_whose_budget_is_not_in_the_settings(): void
    {
        $this->configureKinds(['test.nobudget' => [...$this->kindSettings(), 'budget' => 'missing']]);
        $problem = '種類 test.nobudget の予算の区分 missing が設定(config/ai_runs.php の budget)に無い';

        $this->assertSame([$problem], app(PromptKinds::class)->problems());
    }

    public function test_detects_a_budget_without_the_messages_for_exceeding(): void
    {
        config(['ai_runs.budget.custom' => ['daily_usd' => null, 'monthly_usd' => null, 'total_usd' => null]]);

        $this->assertSame(['予算の区分 custom に超えたときの文が無い(RunBudget)'], app(PromptKinds::class)->problems());
    }

    public function test_detects_a_job_time_that_is_not_below_the_retry_after_of_the_queue(): void
    {
        config(['ai_runs.kinds' => ['test.slow' => [...$this->kindSettings(), 'timeout_seconds' => 300]]]);
        $problem = '種類 test.slow のジョブの時間 620 秒がキューの retry_after 620 秒以上(config/queue.php)';

        config(['queue.connections.database.retry_after' => 621]);
        $this->assertSame([], app(PromptKinds::class)->problems());

        config(['queue.connections.database.retry_after' => 620]);
        $this->assertSame([$problem], app(PromptKinds::class)->problems());
    }

    public function test_detects_two_implementations_of_a_code(): void
    {
        $this->app->tag([RecordingPromptKind::class, RecordingPromptKind::class], PromptKind::TAG);
        $this->configureKinds([RecordingPromptKind::CODE => $this->kindSettings()]);

        $this->assertSame(['種類 test.summary の実装が 2 つある'], app(PromptKinds::class)->problems());
    }

    public function test_detects_an_implementation_whose_code_is_not_in_the_settings(): void
    {
        $this->app->tag([ThrowingPromptKind::class], PromptKind::TAG);

        $this->assertSame(['種類 test.throwing が設定(config/ai_runs.php の kinds)に無い'], app(PromptKinds::class)->problems());
    }

    public function test_detects_a_kind_without_pricing_or_with_a_bounded_last_tier(): void
    {
        $this->configureKinds(['test.unpriced' => $this->kindSettings('claude-unknown'), 'test.bounded' => $this->kindSettings('claude-bounded')]);
        config(['ai_runs.pricing.anthropic.claude-bounded' => [['up_to_input_tokens' => 100, 'input' => 1.0, 'output' => 1.0, 'cache_read' => 1.0, 'cache_write' => 1.0]]]);

        $this->assertSame([
            '種類 test.unpriced の接続先とモデルの単価が無いか、最後の段に入力の上限がある(config/ai_runs.php の pricing)',
            '種類 test.bounded の接続先とモデルの単価が無いか、最後の段に入力の上限がある(config/ai_runs.php の pricing)',
        ], app(PromptKinds::class)->problems());
    }

    public function test_settings_without_an_implementation_are_allowed(): void
    {
        $this->configureKinds(['test.later' => $this->kindSettings()]);

        $this->assertSame([], app(PromptKinds::class)->problems());
    }

    /**
     * @param  array<string, array<string, mixed>>  $kinds
     */
    private function configureKinds(array $kinds): void
    {
        config(['ai_runs.kinds' => [...(array) config('ai_runs.kinds'), ...$kinds]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function kindSettings(string $model = 'claude-haiku-5-5'): array
    {
        return [
            'provider' => 'anthropic',
            'model' => $model,
            'budget' => 'default',
            'timeout_seconds' => 100,
            'max_output_tokens' => 100,
            'max_input_chars' => 1000,
        ];
    }
}
