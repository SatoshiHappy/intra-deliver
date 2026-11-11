<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\KindLimitChecks;
use App\Actions\Ai\PromptKinds;
use App\Queries\Ai\ConversationSettings;
use App\Queries\Ai\KindSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 起動の時の確かめは、形の合わない値を読む確かめと前提の崩れた確かめだけを止め、ほかを続ける(食い違いの文を見落とさないため。
 * 組の一覧 = docs の 設計/AI/実行と費用/接続先と費用.md 5.4 の読む値と前提を 1 つずつ崩したもの)。
 */
final class KindLimitChecksStopTest extends TransactionalTestCase
{
    /** 確かめ → 走ったときに出る文の一部 */
    private const CHECKS = [
        '区分がある' => '種類 test.none の予算の区分 none が設定',
        'ジョブの時間(文章)' => '種類 test.none のジョブの時間',
        'ジョブの時間(文字起こし)' => '種類 test.long のジョブの時間',
        '時間の倍数' => '種類 test.long の 1 本の長さの上限',
        '区切り > 無音を探す幅' => '種類 test.short の区切りの長さ',
        '単価(文章)' => '種類 test.unpriced の接続先とモデルの単価が無いか',
        '単価(文字起こし)' => '種類 test.unpriced-audio の接続先とモデルの音声の分単価が無い',
        '超えたときの文' => '予算の区分 custom に超えたときの文が無い',
        '見積もりの上限(文章の属する区分)' => '予算の区分 default の守る条件を破る',
        '見積もりの上限(文字起こしだけの区分)' => '予算の区分 transcription の守る条件を破る',
    ];

    /** 前提の確かめ(区切り > 無音を探す幅)が食い違う種類のジョブの時間(どの行でも走らない) */
    private const AFTER_FAILED_PREREQUISITE = '種類 test.short のジョブの時間';

    /** 区分の表(見積もりの上限の確かめが走れば破る上限と、超えたときの文の無い区分) */
    private const BUDGETS = [
        'default' => ['daily_usd' => 0.000001, 'monthly_usd' => null, 'total_usd' => null],
        'transcription' => ['daily_usd' => 0.000001, 'monthly_usd' => null, 'total_usd' => null],
        'custom' => ['daily_usd' => null, 'monthly_usd' => null, 'total_usd' => null],
    ];

    /** ジョブの時間が retry_after 以上になる呼び出しの時間の上限(秒) */
    private const LONG = 100_000;

    private const SHORT = 10;

    /**
     * 種類の確かめの行 → 設定(鍵 → 値)・種類の差し替え(コード → 足す値)・止まる確かめ。
     *
     * @return array<string, array{array<string, mixed>, array<string, array<string, mixed>>, list<string>}>
     */
    public static function kindRows(): array
    {
        $jobTimes = ['ジョブの時間(文章)', 'ジョブの時間(文字起こし)'];
        $ceilings = ['見積もりの上限(文章の属する区分)', '見積もりの上限(文字起こしだけの区分)'];
        $allButMessages = array_values(array_diff(array_keys(self::CHECKS), ['超えたときの文']));
        $unfit = ['provider' => ''];

        return [
            'どれも合う' => [[], [], []],
            '種類の表' => [['ai_runs.kinds' => 'x'], [], $allButMessages],
            '文章の種類 test.none の設定' => [[], ['test.none' => $unfit], ['区分がある', $jobTimes[0]]],
            '文字起こしの種類 test.long の設定' => [[], ['test.long' => $unfit], ['時間の倍数', $jobTimes[1], $ceilings[1]]],
            '文字起こしの種類 test.short の設定' => [[], ['test.short' => $unfit], ['区切り > 無音を探す幅', $ceilings[1]]],
            '区分の表' => [['ai_runs.budget' => 'x'], [], ['区分がある', '超えたときの文', ...$ceilings]],
            'retry_after' => [['queue.connections.database.retry_after' => 0], [], $jobTimes],
            '無音を探す幅' => [['ai_runs.transcription.silence_window_seconds' => 0], [], [$jobTimes[1], '区切り > 無音を探す幅']],
            '変換の時間の上限' => [['ai_runs.transcription.conversion_timeout_seconds' => 0], [], [$jobTimes[1]]],
            'どの確かめも読まない文字起こしの値' => [['ai_runs.transcription.silence_min_seconds' => 0], [], []],
        ];
    }

    /**
     * 単価と区分の確かめの行(形は {@see self::kindRows()})。
     *
     * @return array<string, array{array<string, mixed>, array<string, array<string, mixed>>, list<string>}>
     */
    public static function priceAndBudgetRows(): array
    {
        $ceilings = ['見積もりの上限(文章の属する区分)', '見積もりの上限(文字起こしだけの区分)'];
        $unfitting = [...self::prompt('default', self::SHORT, 'claude-sonnet-5-5'), 'provider' => ''];
        $badTiers = [['up_to_input_tokens' => 'x']];
        $badAudio = ['per_audio_minute' => 'x'];
        $unpriced = ['model' => 'no-such-model'];

        return [
            '単価の表' => [['ai_runs.pricing' => 'x'], [], ['単価(文章)', '単価(文字起こし)', ...$ceilings]],
            '文章の種類 test.unpriced の設定' => [[], ['test.unpriced' => ['provider' => '']], ['単価(文章)']],
            '文章の種類 test.unpriced の単価の項目' => [['ai_runs.pricing.anthropic.no-such-model' => $badTiers], [], ['単価(文章)']],
            '文字起こしの種類 test.unpriced-audio の設定' => [[], ['test.unpriced-audio' => ['provider' => '']], ['単価(文字起こし)']],
            '文字起こしの種類 test.unpriced-audio の単価の項目' => [
                ['ai_runs.pricing.gemini.no-such-model' => $badAudio], [], ['単価(文字起こし)'],
            ],
            '区分 default の期間の上限' => [['ai_runs.budget.default.daily_usd' => 'x'], [], [$ceilings[0]]],
            '区分 transcription の期間の上限' => [['ai_runs.budget.transcription.daily_usd' => 'x'], [], [$ceilings[1]]],
            '区分 default の種類の単価の項目' => [
                ['ai_runs.pricing.anthropic.claude-sonnet-5-5' => $badTiers], [], [$ceilings[0]],
            ],
            '区分 transcription の種類の単価の項目' => [
                ['ai_runs.pricing.gemini' => ['gemini-3.5-transcribe' => $badAudio]], [], [$ceilings[1]],
            ],
            '前提: 区分 default の種類の単価の確かめ' => [[], ['test.default' => $unpriced], [$ceilings[0]]],
            '前提: 区分 transcription の種類の単価の確かめ' => [[], ['test.long' => $unpriced], [$ceilings[1]]],
            '見積もりの表' => [['ai_runs.estimate.chars_factor' => 'x'], [], [$ceilings[0]]],
            '区分 default に属する種類の設定' => [[], ['test.bad' => $unfitting], [$ceilings[0]]],
            '前提: 区分を読めない形の合わない種類' => [[], ['test.bad' => [...$unfitting, 'budget' => '']], $ceilings],
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, array<string, mixed>>  $kinds
     * @param  list<string>  $stopped
     */
    #[DataProvider('kindRows')]
    #[DataProvider('priceAndBudgetRows')]
    public function test_a_check_stops_only_when_a_value_it_reads_or_its_prerequisite_does_not_fit(
        array $settings,
        array $kinds,
        array $stopped,
    ): void {
        config(['ai_runs.kinds' => self::kinds($kinds), 'ai_runs.budget' => self::BUDGETS]);
        config($settings);

        $problems = app(KindLimitChecks::class)->problems(app(KindSettings::class)->all());
        $ran = array_filter(self::CHECKS, fn (string $text): bool => self::contains($problems, $text));

        $this->assertSame(array_values(array_diff(array_keys(self::CHECKS), $stopped)), array_keys($ran));
        $this->assertFalse(self::contains($problems, self::AFTER_FAILED_PREREQUISITE));
    }

    /**
     * 行 → 設定(鍵 → 値)・用途の単価の確かめが走るか・同じ用途のほかの確かめ(足す指定の鍵)が走るか。
     *
     * @return array<string, array{array<string, mixed>, bool, bool}>
     */
    public static function purposeRows(): array
    {
        $tierShape = [['up_to_input_tokens' => 'x']];

        return [
            'どれも合う' => [[], true, true],
            '用途の設定が合わない' => [['ai_runs.conversation.purposes.title.provider' => ''], false, false],
            '単価の表が配列でない' => [['ai_runs.pricing' => 'x'], false, true],
            '単価の項目の形が合わない' => [['ai_runs.pricing.anthropic.no-such-model' => $tierShape], false, true],
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    #[DataProvider('purposeRows')]
    public function test_the_price_of_a_conversation_purpose_stops_only_when_the_pricing_it_reads_does_not_fit(
        array $settings,
        bool $priced,
        bool $optionsChecked,
    ): void {
        config([
            'ai_runs.conversation.purposes.title.model' => 'no-such-model',
            'ai_runs.conversation.purposes.title.provider_options' => [KindSettings::RUN_OPTION_KEY => 'x'],
        ]);
        config($settings);

        $problems = app(ConversationSettings::class)->problems();

        $this->assertSame($priced, self::contains($problems, '会話の用途 title の接続先とモデルの単価が無いか'));
        $this->assertSame($optionsChecked, self::contains($problems, '会話の用途 title の provider_options が'));
    }

    /** 単価の表が配列でなくても、文章・文字起こしの種類と会話の用途の確かめは投げずに、表の文だけを返す */
    public function test_the_startup_check_returns_the_text_when_the_pricing_table_is_not_an_array(): void
    {
        config(['ai_runs.pricing' => 'x']);

        $this->assertSame(['config/ai_runs.php の pricing が配列でない'], app(PromptKinds::class)->problems());
    }

    /**
     * 確かめの文が出る種類の設定に、差し替え $overrides(コード → 足す値。無いコードは足す)を当てたもの。
     *
     * @param  array<string, array<string, mixed>>  $overrides
     * @return array<string, array<string, mixed>>
     */
    private static function kinds(array $overrides): array
    {
        $kinds = [
            'test.none' => self::prompt('none', self::LONG, 'claude-haiku-5-5'),
            'test.unpriced' => self::prompt('none', self::SHORT, 'no-such-model'),
            'test.default' => self::prompt('default', self::SHORT, 'claude-sonnet-5-5'),
            'test.long' => self::transcription('transcription', self::LONG, 1800, 1500, 'gemini-3.5-transcribe'),
            'test.short' => self::transcription('transcription', self::LONG, 3600, 30, 'gemini-3.5-transcribe'),
            'test.unpriced-audio' => self::transcription('none', self::SHORT, 3600, 1500, 'no-such-model'),
        ];

        foreach ($overrides as $code => $values) {
            $kinds[$code] = [...($kinds[$code] ?? []), ...$values];
        }

        return $kinds;
    }

    /**
     * @return array<string, mixed>
     */
    private static function prompt(string $budget, int $timeout, string $model): array
    {
        return [
            'type' => KindSettings::PROMPT, 'provider' => 'anthropic', 'model' => $model, 'budget' => $budget,
            'timeout_seconds' => $timeout, 'max_output_tokens' => 100, 'max_input_chars' => 1000,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function transcription(string $budget, int $timeout, int $maxAudio, int $chunk, string $model): array
    {
        return [
            'type' => KindSettings::TRANSCRIPTION, 'provider' => 'gemini', 'model' => $model, 'budget' => $budget,
            'timeout_seconds' => $timeout, 'language' => 'ja',
            'max_audio_seconds' => $maxAudio, 'chunk_seconds' => $chunk,
        ];
    }

    /**
     * @param  list<string>  $problems
     */
    private static function contains(array $problems, string $text): bool
    {
        return array_any($problems, fn (string $problem): bool => str_contains($problem, $text));
    }
}
