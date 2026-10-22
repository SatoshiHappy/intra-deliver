<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
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
}
