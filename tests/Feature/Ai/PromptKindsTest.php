<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\PromptKind;
use App\Actions\Ai\PromptKinds;
use App\Queries\Ai\AiSettings;
use App\Queries\Ai\RunEstimates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\TransactionalTestCase;

/**
 * 種類の登録と設定の食い違いを起動のテストで落とす(docs の 設計/AI/公開の口.md 3.1、実行と費用.md 4.1・5 節): 起動(AppServiceProvider)の登録と
 * config/ai_runs.php は食い違わない。同じコードの実装が 2 つ・設定に無いコードの実装・単価が無い種類・予算の区分が設定に無い種類・
 * ジョブの時間が retry_after 以上の種類・区分の守る条件(1 依頼の見積もりの上限の最大 < 空でない最も小さい期間の上限。実行と費用.md 5.2)を破る設定・
 * 既定の DB 接続の database でないキューは食い違いとして出る。
 */
final class PromptKindsTest extends TransactionalTestCase
{
    public function test_the_registered_kinds_and_the_settings_agree(): void
    {
        $this->assertSame([], app(PromptKinds::class)->problems());
    }

    /**
     * 今の設定の値で守る条件が成り立つ(区分の 1 依頼の見積もりの上限の最大 < 日の上限 = その日の最初の依頼が通る。
     * 見積もりの上限 = 入力の上限の文字数と出力の上限で見積もった額。default は抜き出しの約 0.284 ドルが最大 < 5)。
     */
    public function test_one_request_at_the_ceiling_fits_in_the_daily_budget_with_the_current_settings(): void
    {
        $ceiling = app(RunEstimates::class)->ceilingOf('default');
        $daily = app(AiSettings::class)->budget('default', 'daily_usd');

        $this->assertSame('0.284000', $ceiling);
        $this->assertLessThan($daily, (float) $ceiling);
    }

    /** haiku の出力の単価 0.50 ドル / 100 万トークンで、出力の上限 1,000 万トークンの 1 依頼の見積もりは 5 ドルを超える */
    public function test_detects_a_daily_limit_one_request_can_use_up(): void
    {
        $this->configureKinds(['test.big' => [...$this->kindSettings(), 'max_output_tokens' => 10_000_000]]);
        $problem = '予算の区分 default の守る条件を破る: 1 依頼の見積もりの上限の最大が日の上限 5.000000 ドル以上';

        $this->assertSame([$problem], app(PromptKinds::class)->problems());

        config(['ai_runs.budget.default.daily_usd' => null]);

        $this->assertSame([], app(PromptKinds::class)->problems());
    }

    /**
     * 守る条件の右辺は、日・月・累計の上限のうち空でない最も小さいもの(実行と費用.md 5.2。3 つとも空なら確かめない)。
     * 今の設定の default の 1 依頼の見積もりの上限の最大は 0.284000 ドル(上のテスト)。境界は見積もりの上限と同じ額(以上で止まる)と、
     * その両側の 100 万分の 1 ドル(額の刻み)。同じ額の期間が 2 つ以上なら短い期間の名前を出す。
     *
     * @return array<string, array{float|null, float|null, float|null, string|null}>
     */
    public static function smallestLimits(): array
    {
        return [
            '3 つとも空' => [null, null, null, null],
            '日が空・月が見積もりの上限の 1 刻み下' => [null, 0.283999, null, '月の上限 0.283999'],
            '日が空・月が見積もりの上限と同じ' => [null, 0.284, null, '月の上限 0.284000'],
            '日が空・月が見積もりの上限の 1 刻み上' => [null, 0.284001, null, null],
            '日と月が空・累計が見積もりの上限と同じ' => [null, null, 0.284, '累計の上限 0.284000'],
            '日が空・最も小さいのが累計' => [null, 100.0, 0.284, '累計の上限 0.284000'],
            '月が日より小さい' => [1.0, 0.284, null, '月の上限 0.284000'],
            '日と月が同じ額' => [0.284, 0.284, 200.0, '日の上限 0.284000'],
            '今の公開デモの既定(日 5・月 50・累計 200)' => [5.0, 50.0, 200.0, null],
        ];
    }

    #[DataProvider('smallestLimits')]
    public function test_compares_the_ceiling_with_the_smallest_limit_that_is_set(
        ?float $daily,
        ?float $monthly,
        ?float $total,
        ?string $exceeded,
    ): void {
        config(['ai_runs.budget.default' => ['daily_usd' => $daily, 'monthly_usd' => $monthly, 'total_usd' => $total]]);
        $problem = "予算の区分 default の守る条件を破る: 1 依頼の見積もりの上限の最大が{$exceeded} ドル以上";

        $this->assertSame($exceeded === null ? [] : [$problem], app(PromptKinds::class)->problems());
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

    /**
     * 文字起こしの種類のジョブの時間はもっと長いので、登録した会議の文字起こしの種類を短い設定にし(区切りは 2 つ・呼び出し 1 秒・変換 1 秒)、
     * 文章の種類の時間だけを見る。
     */
    public function test_detects_a_job_time_that_is_not_below_the_retry_after_of_the_queue(): void
    {
        $this->configureKinds(['test.slow' => [...$this->kindSettings(), 'timeout_seconds' => 301]]);
        $kinds = (array) config('ai_runs.kinds');
        $kinds['meeting.transcription'] = [
            ...(array) $kinds['meeting.transcription'],
            'timeout_seconds' => 1,
            'max_audio_seconds' => 3600,
            'chunk_seconds' => 3600,
        ];
        config([
            'ai_runs.kinds' => $kinds,
            'ai_runs.transcription.conversion_timeout_seconds' => 1,
            'ai_runs.transcription.silence_window_seconds' => 1,
        ]);
        $problem = '種類 test.slow のジョブの時間 622 秒がキューの retry_after 622 秒以上(config/queue.php)';

        config(['queue.connections.database.retry_after' => 623]);
        $this->assertSame([], app(PromptKinds::class)->problems());

        config(['queue.connections.database.retry_after' => 622]);
        $this->assertSame([$problem], app(PromptKinds::class)->problems());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function queueStores(): array
    {
        $afterCommit = '設定 queue.connections.database.after_commit(config/queue.php)が真'
            .'(ジョブの投入がコミットの後に回り、実行の記録と同じトランザクションに入らない)';

        return [
            '既定のキューが database でない' => [['queue.default' => 'sync'], '既定のキューの接続(QUEUE_CONNECTION)が DB の表(database)でない'],
            '接続が既定の DB 接続と違う' => [
                ['queue.connections.database.connection' => 'other'],
                '設定 queue.connections.database.connection(DB_QUEUE_CONNECTION)が既定の DB の接続でない',
            ],
            'after_commit が真' => [['queue.connections.database.after_commit' => true], $afterCommit],
        ];
    }

    /**
     * ジョブを実行の記録と同じトランザクションで入れる前提(docs の 設計/AI/データと失敗と検査.md 8 節): 既定のキューが既定の DB 接続の database で、
     * after_commit が偽でなければ食い違い。テストの環境も同じに確かめる(phpunit.xml の QUEUE_CONNECTION = database)。
     *
     * @param  array<string, mixed>  $settings
     */
    #[DataProvider('queueStores')]
    public function test_detects_a_queue_not_in_the_default_database(array $settings, string $message): void
    {
        config($settings);

        $this->assertSame([$message], app(PromptKinds::class)->problems());
    }

    /**
     * キューの接続の DB の接続が空(null・空文字)なら既定の DB 接続とみなす。
     */
    #[TestWith([null])]
    #[TestWith([''])]
    public function test_a_queue_connection_left_empty_passes(?string $connection): void
    {
        config(['queue.connections.database.connection' => $connection]);

        $this->assertSame([], app(PromptKinds::class)->problems());
    }

    public function test_a_queue_connection_named_as_the_default_passes(): void
    {
        config(['queue.connections.database.connection' => config('database.default')]);

        $this->assertSame([], app(PromptKinds::class)->problems());
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

    /** 訪問者の ID は実行ごとに metadata に足すので、種類の設定は metadata を持たない(docs の 設計/AI/実行と費用.md 4.4) */
    public function test_detects_provider_options_with_the_metadata_of_the_run(): void
    {
        $options = ['metadata' => ['user_id' => 'fixed']];
        $this->configureKinds(['test.metadata' => [...$this->kindSettings(), 'provider_options' => $options]]);

        $this->assertSame(
            ['種類 test.metadata(prompt)の provider_options が実行ごとに足す鍵 metadata を持つ(config/ai_runs.php の kinds)'],
            app(PromptKinds::class)->problems(),
        );
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
            'type' => 'prompt',
            'provider' => 'anthropic',
            'model' => $model,
            'budget' => 'default',
            'timeout_seconds' => 100,
            'max_output_tokens' => 100,
            'max_input_chars' => 1000,
        ];
    }
}
