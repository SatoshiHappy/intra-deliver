<?php

namespace Tests\Feature\Deploy;

use Illuminate\Support\Facades\Artisan;
use Tests\Support\BootsWithEnvironmentOverrides;
use Tests\Support\PublishSettingsTemplate;
use Tests\TestCase;

/**
 * 公開用の app.env の雛形の AI の値(AI の費用の上限)で起動したアプリで、起動の時の確かめ ai:check-settings
 * (docs の 設計/AI/データと失敗と検査.md の 8 節。公開用のエントリポイントが起動のたびに流す)が通る。
 * 値は環境変数として置いてからアプリを作る(設定ファイルが env() で読むので。仕組みは BootsWithEnvironmentOverrides)。
 * 起動の後に設定を書き換える形にしないのは、雛形の値が設定ファイルの読み方(空は上限なし・数の文字列)を通って入ることも確かめるため。
 */
final class PublishedAiSettingsTest extends TestCase
{
    use BootsWithEnvironmentOverrides;

    /**
     * @return array<string, string>
     */
    protected function environmentOverrides(): array
    {
        return array_filter(
            PublishSettingsTemplate::section('app.env'),
            fn (string $name): bool => str_starts_with($name, 'AI_'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    public function test_the_template_values_are_loaded(): void
    {
        $this->assertSame('5', config('ai_runs.budget.default.daily_usd'));
        $this->assertNull(config('ai_runs.budget.transcription.total_usd'));
    }

    public function test_the_startup_check_passes_with_the_template_values(): void
    {
        $this->assertSame(0, Artisan::call('ai:check-settings'));
        $this->assertSame("AI の設定に食い違いは無い\n", Artisan::output());
    }
}
