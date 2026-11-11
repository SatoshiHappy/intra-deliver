<?php

namespace Tests\Feature\Ai;

use App\Queries\Ai\AiSettings;
use App\Queries\Ai\KindSettings;
use App\Queries\Ai\RunEstimates;
use App\Queries\Ai\TranscriptionSettings;
use Closure;
use Illuminate\Support\Facades\Artisan;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 起動の時の確かめの中の設定の値の形の誤り(docs の 設計/AI/データと失敗と検査.md 8 節): PromptKinds::problems() から届く読み方の形の誤りは、どれも投げずに
 * 食い違いの文になり、ai:check-settings がほかの食い違い(会話の接続の名前が空)と一緒に出して終了コード 1 で終わる。値を使う読み方は今のとおり
 * 作りの誤り(例外の文は食い違いの文と同じ)。
 */
final class SettingsShapeChecksTest extends TransactionalTestCase
{
    private const OTHER_PROBLEM = 'config/ai_runs.php の conversation.queue_connection が空でない文字列でない';

    private const NOT_NUMBER_FACTOR = 'config/ai_runs.php の estimate.chars_factor が数でない';

    private const NOT_AUDIO_TABLE = 'config/ai_runs.php の pricing.gemini.gemini-3.5-transcribe'
        .'.per_audio_minute の形が合わない';

    /**
     * 形の誤りの設定(設定の鍵 → 値)と、その食い違いの文。
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function malformedSettings(): array
    {
        $haiku = 'pricing.anthropic.claude-haiku-5-5';
        $tier = ['up_to_input_tokens' => null, 'input' => 0.1, 'output' => 0.5, 'cache_read' => 0.01];

        return [
            '種類の表が配列でない' => [['ai_runs.kinds' => 'x'], 'config/ai_runs.php の kinds が配列でない'],
            '単価の表が配列でない' => [['ai_runs.pricing' => 'x'], 'config/ai_runs.php の pricing が配列でない'],
            '単価の段の形' => [["ai_runs.{$haiku}" => [['input' => 0.1]]], "config/ai_runs.php の {$haiku} の段の形が合わない"],
            '単価の段の単価が数でない' => [["ai_runs.{$haiku}" => [$tier]], "config/ai_runs.php の {$haiku}.cache_write が数でない"],
            '音声の分単価が数でない' => [
                ['ai_runs.pricing.gemini' => ['gemini-3.5-transcribe' => ['per_audio_minute' => ['input' => 'x']]]],
                'config/ai_runs.php の pricing.gemini.gemini-3.5-transcribe.per_audio_minute.input が数でない',
            ],
            '音声の分単価が配列でない' => [
                ['ai_runs.pricing.gemini' => ['gemini-3.5-transcribe' => ['per_audio_minute' => 'x']]],
                self::NOT_AUDIO_TABLE,
            ],
            '予算の区分の表が配列でない' => [['ai_runs.budget' => 'x'], 'config/ai_runs.php の budget が配列でない'],
            '予算の区分が配列でない' => [['ai_runs.budget.default' => 'x'], 'config/ai_runs.php の budget の区分 default が無いか配列でない'],
            '区分の日の上限が数でない' => [
                ['ai_runs.budget.default.daily_usd' => 'x'],
                'config/ai_runs.php の budget.default.daily_usd が数でない',
            ],
            '見積もりの表が配列でない' => [['ai_runs.estimate' => 'x'], 'config/ai_runs.php の estimate が配列でない'],
            '見積もりの係数が数でない' => [['ai_runs.estimate.chars_factor' => 'x'], self::NOT_NUMBER_FACTOR],
            '見積もりの上乗せが数でない' => [
                ['ai_runs.estimate.overhead_tokens' => null],
                'config/ai_runs.php の estimate.overhead_tokens が数でない',
            ],
            '配り直すまでの時間が正の整数でない' => [
                ['queue.connections.database.retry_after' => '90'],
                'config/queue.php の connections.database.retry_after が正の整数でない',
            ],
            '文字起こしの値の表が配列でない' => [['ai_runs.transcription' => 'x'], 'config/ai_runs.php の transcription が配列でない'],
            '無音を探す幅が正の整数でない' => [
                ['ai_runs.transcription.silence_window_seconds' => 0],
                'config/ai_runs.php の transcription.silence_window_seconds が正の整数でない',
            ],
            '変換の時間の上限が正の整数でない' => [
                ['ai_runs.transcription.conversion_timeout_seconds' => 'x'],
                'config/ai_runs.php の transcription.conversion_timeout_seconds が正の整数でない',
            ],
            '既定のキューの接続の名前が文字列でない' => [
                ['queue.default' => null],
                '既定のキューの接続の名前(config/queue.php の default = QUEUE_CONNECTION)が文字列でない',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    #[DataProvider('malformedSettings')]
    public function test_a_malformed_value_is_listed_with_the_other_problems(array $settings, string $problem): void
    {
        config(['ai_runs.conversation.queue_connection' => '', ...$settings]);

        $this->assertSame(1, Artisan::call('ai:check-settings'));
        $output = explode("\n", trim(Artisan::output()));
        $this->assertContains($problem, $output);
        $this->assertContains(self::OTHER_PROBLEM, $output);
    }

    public function test_a_non_string_key_of_the_provider_options_is_listed_with_the_other_problems(): void
    {
        $kinds = (array) config('ai_runs.kinds');
        $kinds['meeting.minutes']['provider_options'] = ['x'];
        config(['ai_runs.kinds' => $kinds, 'ai_runs.conversation.queue_connection' => '']);

        $this->assertSame(1, Artisan::call('ai:check-settings'));
        $output = explode("\n", trim(Artisan::output()));
        $this->assertContains('config/ai_runs.php の kinds.meeting.minutes.provider_options の鍵が文字列でない', $output);
        $this->assertContains(self::OTHER_PROBLEM, $output);
    }

    /**
     * 値を使う読み方は作りの誤り(例外の文は今のまま)。
     *
     * @return array<string, array{array<string, mixed>, Closure(): mixed, string}>
     */
    public static function valueReads(): array
    {
        $haiku = 'pricing.anthropic.claude-haiku-5-5';

        return [
            '単価の段' => [
                ["ai_runs.{$haiku}" => [['input' => 0.1]]],
                fn (): mixed => app(AiSettings::class)->priceTiers('anthropic', 'claude-haiku-5-5'),
                "config/ai_runs.php の {$haiku} の段の形が合わない",
            ],
            '音声の分単価' => [
                ['ai_runs.pricing.gemini' => ['gemini-3.5-transcribe' => ['per_audio_minute' => 'x']]],
                fn (): mixed => app(AiSettings::class)->audioMinutePrice('gemini', 'gemini-3.5-transcribe'),
                self::NOT_AUDIO_TABLE,
            ],
            '区分の上限' => [
                ['ai_runs.budget.default.daily_usd' => 'unlimited'],
                fn (): mixed => app(AiSettings::class)->budget('default', 'daily_usd'),
                'config/ai_runs.php の budget.default.daily_usd が数でない',
            ],
            '配り直すまでの時間' => [
                ['queue.connections.database.retry_after' => '90'],
                fn (): mixed => app(AiSettings::class)->queueRetryAfter(),
                'config/queue.php の connections.database.retry_after が正の整数でない',
            ],
            '文字起こしの値' => [
                ['ai_runs.transcription.silence_window_seconds' => 0],
                fn (): mixed => app(TranscriptionSettings::class)->silenceWindowSeconds(),
                'config/ai_runs.php の transcription.silence_window_seconds が正の整数でない',
            ],
            '無音の大きさが負でない' => [
                ['ai_runs.transcription.silence_noise_db' => 1],
                fn (): mixed => app(TranscriptionSettings::class)->silenceNoiseDb(),
                'config/ai_runs.php の transcription.silence_noise_db が負の数でない',
            ],
            '種類の表' => [
                ['ai_runs.kinds' => 'x'],
                fn (): mixed => app(KindSettings::class)->settings('meeting.minutes'),
                'config/ai_runs.php の kinds が配列でない',
            ],
            '区分の見積もりの上限' => [
                ['ai_runs.estimate.chars_factor' => 'x'],
                fn (): mixed => app(RunEstimates::class)->ceilingOf('default'),
                'config/ai_runs.php の estimate.chars_factor が数でない',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  Closure(): mixed  $read
     */
    #[DataProvider('valueReads')]
    public function test_a_read_of_a_malformed_value_is_an_error(array $settings, Closure $read, string $text): void
    {
        config($settings);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($text);

        $read();
    }

    public function test_a_read_of_a_non_string_key_of_the_provider_options_is_a_construction_error(): void
    {
        $kinds = (array) config('ai_runs.kinds');
        $kinds['meeting.minutes']['provider_options'] = ['x'];
        config(['ai_runs.kinds' => $kinds]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('config/ai_runs.php の kinds.meeting.minutes.provider_options の鍵が文字列でない');

        app(KindSettings::class)->settings('meeting.minutes');
    }
}
