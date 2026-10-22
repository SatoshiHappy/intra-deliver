<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\PromptKind;
use App\Actions\Ai\Api\TranscriptionKind;
use App\Actions\Ai\PromptKinds;
use App\Queries\Ai\KindSettings;
use App\Queries\Ai\RunEstimates;
use App\Queries\Ai\TranscriptionSettings;
use Tests\Support\TransactionalTestCase;

/**
 * 文字起こしの種類の設定を起動のテストで落とす(docs の 設計/AI/データと失敗と検査.md 8 節、実行と費用.md 4.1・5 節・5.2): 型ごとの必須の鍵、
 * 分単価、区分、区分の守る条件(1 本の長さの上限の見積もり < 日の上限)、1 本の長さの上限が 3600 秒の倍数、ジョブの時間 < retry_after、
 * 文章と文字起こしの 2 つの型をまたいでコードが一意・実装に設定がある。今の設定では食い違いが無い。
 */
final class TranscriptionKindChecksTest extends TransactionalTestCase
{
    public function test_the_current_settings_have_no_problem_and_one_three_hour_audio_fits_in_the_daily_budget(): void
    {
        $kind = app(KindSettings::class)->transcription('meeting.transcription');
        $this->assertNotNull($kind);

        $this->assertSame([], $this->problems());
        $this->assertSame('0.900000', app(RunEstimates::class)->ceilingOf('transcription'));
        $settings = app(TranscriptionSettings::class);
        $this->assertSame([8, 3340], [$settings->maxChunks($kind), $settings->jobTimeout($kind)]);
        $this->assertNull(app(KindSettings::class)->prompt('meeting.transcription'));
        $this->assertNull(app(KindSettings::class)->transcription('meeting.minutes'));
    }

    public function test_detects_a_kind_without_a_known_type(): void
    {
        $this->configureKind('test.untyped', ['type' => null]);
        $this->configureKind('test.other', ['type' => 'image']);

        $this->assertSame([
            '種類 test.untyped の type が prompt・transcription のどちらでもない(config/ai_runs.php の kinds)',
            '種類 test.other の type が prompt・transcription のどちらでもない(config/ai_runs.php の kinds)',
        ], $this->problems());
    }

    public function test_detects_the_missing_keys_of_each_type(): void
    {
        $this->configureKind('test.transcription', ['language' => null, 'chunk_seconds' => 0]);
        $this->configureKind('test.prompt', [
            'type' => 'prompt', 'model' => 'claude-haiku-5-5', 'budget' => 'default', 'max_output_tokens' => 100,
        ]);

        $this->assertSame([
            '種類 test.transcription(transcription)の language・chunk_seconds が無いか形が合わない(config/ai_runs.php の kinds)',
            '種類 test.prompt(prompt)の max_input_chars が無いか形が合わない(config/ai_runs.php の kinds)',
        ], $this->problems());
    }

    public function test_detects_a_kind_without_the_price_per_audio_minute(): void
    {
        $this->configureKind('test.unpriced', ['model' => 'gemini-unknown']);
        $this->configureKind('test.tokens', ['provider' => 'anthropic', 'model' => 'claude-haiku-5-5']);

        $this->assertSame([
            '種類 test.unpriced の接続先とモデルの音声の分単価が無い(config/ai_runs.php の pricing)',
            '種類 test.tokens の接続先とモデルの音声の分単価が無い(config/ai_runs.php の pricing)',
        ], $this->problems());
    }

    public function test_detects_a_kind_whose_budget_is_not_in_the_settings(): void
    {
        $this->configureKind('test.nobudget', ['budget' => 'missing']);

        $problem = '種類 test.nobudget の予算の区分 missing が設定(config/ai_runs.php の budget)に無い';
        $this->assertSame([$problem], $this->problems());
    }

    /** 3 時間 × 1 分あたり 0.005 ドル = 0.90 ドル。日の上限が 0.90 ドルなら、その日の最初の 1 本も断られる */
    public function test_detects_a_daily_limit_one_audio_at_the_ceiling_can_use_up(): void
    {
        config(['ai_runs.budget.transcription.daily_usd' => 0.9]);
        $problem = '予算の区分 transcription の守る条件を破る: 1 依頼の見積もりの上限の最大が日の上限 0.900000 ドル以上';

        $this->assertSame([$problem], $this->problems());

        config(['ai_runs.budget.transcription.daily_usd' => 0.900001]);

        $this->assertSame([], $this->problems());
    }

    public function test_detects_a_maximum_length_that_is_not_whole_hours(): void
    {
        $this->configureKind('test.halfhour', ['max_audio_seconds' => 5400]);

        $problem = '種類 test.halfhour の 1 本の長さの上限 5400 秒が 3600 秒の倍数でない(config/ai_runs.php の kinds)';
        $this->assertSame([$problem], $this->problems());
    }

    /** ジョブの時間 = 変換 300 + 区切りの数の上限 ceil(10800 ÷ (1500 − 60)) = 8 ×(180 + 10)× 2 = 3,340 秒 */
    public function test_detects_a_job_time_that_is_not_below_the_retry_after_of_the_queue(): void
    {
        config(['queue.connections.database.retry_after' => 3341]);
        $this->assertSame([], $this->problems());

        config(['queue.connections.database.retry_after' => 3340]);
        $problem = '種類 meeting.transcription のジョブの時間 3340 秒がキューの retry_after 3340 秒以上(config/queue.php)';
        $this->assertSame([$problem], $this->problems());
    }

    public function test_detects_a_chunk_not_longer_than_the_window_to_look_for_silence(): void
    {
        $this->configureKind('test.short', ['chunk_seconds' => 60]);

        $this->assertSame(['種類 test.short の区切りの長さ 60 秒が無音を探す幅以下(config/ai_runs.php)'], $this->problems());
    }

    public function test_detects_the_implementations_against_the_settings_across_the_two_types(): void
    {
        $this->app->tag([RecordingTranscriptionKind::class], TranscriptionKind::TAG);
        $this->assertSame(['種類 test.transcription が設定(config/ai_runs.php の kinds)に無い'], $this->problems());

        $this->configureKind(RecordingTranscriptionKind::CODE, [
            'type' => 'prompt', 'provider' => 'anthropic', 'model' => 'claude-haiku-5-5',
            'max_output_tokens' => 100, 'max_input_chars' => 100,
        ]);
        $mismatch = '種類 test.transcription の設定の type が実装の型 transcription と違う(config/ai_runs.php の kinds)';
        $this->assertSame([$mismatch], $this->problems());

        $this->configureKind(RecordingTranscriptionKind::CODE, []);
        $this->assertSame([], $this->problems());

        $this->app->tag([SameCodePromptKind::class], PromptKind::TAG);
        $this->assertSame([
            '種類 test.transcription の設定の type が実装の型 prompt と違う(config/ai_runs.php の kinds)',
            '種類 test.transcription の実装が 2 つある',
        ], $this->problems());
    }

    /**
     * @return list<string>
     */
    private function problems(): array
    {
        return app(PromptKinds::class)->problems();
    }

    /**
     * 文字起こしの種類を設定に足す(既定 = 公開デモの meeting.transcription と同じ値。値を null にした鍵は外す)。
     *
     * @param  array<string, mixed>  $overrides
     */
    private function configureKind(string $code, array $overrides): void
    {
        $kind = array_filter([
            'type' => 'transcription',
            'provider' => 'gemini',
            'model' => 'gemini-3.5-transcribe',
            'budget' => 'transcription',
            'timeout_seconds' => 180,
            'language' => 'ja',
            'max_audio_seconds' => 10800,
            'chunk_seconds' => 1500,
            ...$overrides,
        ], fn (mixed $value): bool => $value !== null);

        config(['ai_runs.kinds' => [...(array) config('ai_runs.kinds'), $code => $kind]]);
    }
}
