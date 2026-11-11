<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 起動の時の確かめのコマンド ai:check-settings(docs の 設計/AI/データと失敗と検査.md 8 節): 今の設定では終了コード 0、
 * 食い違いのある設定では終了コード 1 と食い違いの一覧。判定は PromptKinds::problems() と同じ(項目ごとのテストは PromptKindsTest・TranscriptionKindChecksTest)。
 */
final class CheckSettingsCommandTest extends TransactionalTestCase
{
    public function test_the_current_settings_pass(): void
    {
        $this->assertSame(0, Artisan::call('ai:check-settings'));
        $this->assertSame("AI の設定に食い違いは無い\n", Artisan::output());
    }

    public function test_a_problem_fails_with_the_list(): void
    {
        config(['queue.connections.database.retry_after' => 3340]);

        $this->assertSame(1, Artisan::call('ai:check-settings'));
        $this->assertSame(
            "種類 meeting.transcription のジョブの時間 3340 秒がキューの retry_after 3340 秒以上(config/queue.php)\n",
            Artisan::output(),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function budgetPeriods(): array
    {
        return [
            '月' => ['default.monthly_usd'],
            '累計' => ['default.total_usd'],
            '文字起こしの月' => ['transcription.monthly_usd'],
        ];
    }

    /**
     * 日だけでなく月・累計の上限も、数でない値は起動の確かめで止まる(上限なしに倒さない = docs の 設計/AI/実行と費用/接続先と費用.md の 5 節)。
     * 確かめの中では投げずに食い違いの文にして終了コード 1(ほかの形の誤りの分 = SettingsShapeChecksTest)。
     */
    #[DataProvider('budgetPeriods')]
    public function test_a_non_numeric_budget_limit_of_any_period_stops_the_check(string $key): void
    {
        config(["ai_runs.budget.{$key}" => 'unlimited']);

        $this->assertSame(1, Artisan::call('ai:check-settings'));
        $this->assertSame("config/ai_runs.php の budget.{$key} が数でない\n", Artisan::output());
    }
}
