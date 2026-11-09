<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\PromptKinds;
use App\Queries\Ai\ConversationSettings;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\TransactionalTestCase;

/**
 * 会話の接続と会話の型の用途の設定を起動の時の確かめで落とす(docs の 設計/AI/データと失敗と検査.md 8 節の「会話の型で足すもの」、
 * 会話の接続 = 設計/AI/実行と費用/README.md 4.1・4.5、用途 = 設計/AI/実行と費用/接続先と費用.md 5 節): 会話の接続は database ドライバ・既定の DB 接続・
 * after_commit が偽で、キューの名前が既定の接続と違い、ジョブの表が同じで、配り直すまでの時間が既定の接続の値以下。3 つの用途は単価があり、足す指定が
 * metadata と作業場を指す鍵 container を持たない。ファイルを作るは接続先の driver が anthropic で、コード実行の時間単価がある。
 * どれも、今の設定(食い違い無し)と 1 つだけ外した設定(その文が返る)で通す。設定の値の形の誤りは、確かめでは投げずに文として返し、ほかの確かめを
 * 続ける(ほかの食い違いと一緒に一覧に出る)。
 */
final class ConversationSettingsChecksTest extends TransactionalTestCase
{
    private const CONNECTION = 'queue.connections.database-agent';

    private const NO_CODE_EXECUTION_PRICE = '会話の用途 create_file の接続先とモデルのコード実行の時間単価が無い'
        .'(config/ai_runs.php の code_execution_pricing)';

    public function test_the_current_settings_have_no_problem(): void
    {
        $settings = app(ConversationSettings::class);

        $this->assertSame([], $settings->problems());
        $this->assertSame([], app(PromptKinds::class)->problems());
        $this->assertSame('database-agent', $settings->queueConnection());
        $title = $settings->purpose('title');
        $this->assertSame(['anthropic', 'claude-haiku-5-5'], [$title['provider'], $title['model']]);
        $price = $settings->codeExecutionPrice('anthropic', 'claude-sonnet-5-5');
        $this->assertSame(['usd_per_hour' => 0.05, 'min_seconds' => 300], $price);
        $this->assertSame(1000000, $settings->contextWindowTokens());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function queueSettings(): array
    {
        $label = '会話のキューの接続 database-agent';

        return [
            'ドライバが database でない' => [
                [self::CONNECTION.'.driver' => 'sync'],
                'キューの接続 database-agent(config/queue.php)が DB の表(database)でない',
            ],
            'DB の接続が既定でない' => [
                [self::CONNECTION.'.connection' => 'other'],
                '設定 queue.connections.database-agent.connection(DB_QUEUE_CONNECTION)が既定の DB の接続でない',
            ],
            'after_commit が真' => [
                [self::CONNECTION.'.after_commit' => true],
                '設定 queue.connections.database-agent.after_commit(config/queue.php)が真'
                    .'(ジョブの投入がコミットの後に回り、実行の記録と同じトランザクションに入らない)',
            ],
            'キューの名前が既定の接続と同じ' => [
                [self::CONNECTION.'.queue' => 'default'],
                "{$label} のキューの名前が既定のキューの接続と同じ(config/queue.php)",
            ],
            '表が違う' => [[self::CONNECTION.'.table' => 'agent_jobs'], "{$label} のジョブの表が既定のキューの接続と違う(config/queue.php)"],
            '配り直すまでの時間が既定より 1 秒長い' => [
                [self::CONNECTION.'.retry_after' => 3601],
                "{$label} の retry_after 3601 秒が既定のキューの接続の retry_after 3600 秒より長い(config/queue.php)",
            ],
            '配り直すまでの時間が正の整数でない' => [
                [self::CONNECTION.'.retry_after' => 0],
                "{$label} の retry_after が正の整数でない(config/queue.php)",
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    #[DataProvider('queueSettings')]
    public function test_detects_a_conversation_queue_that_does_not_fit(array $settings, string $message): void
    {
        config(['queue.connections.database.retry_after' => 3600, ...$settings]);

        $this->assertSame([$message], app(ConversationSettings::class)->problems());
        $this->assertContains($message, app(PromptKinds::class)->problems());
    }

    /** 配り直すまでの時間の境界 = 既定の接続の値と同じは通り、1 秒短いも通る(1 秒長いは上の表) */
    #[TestWith([3600])]
    #[TestWith([3599])]
    public function test_a_retry_after_up_to_the_default_passes(int $seconds): void
    {
        config(['queue.connections.database.retry_after' => 3600, self::CONNECTION.'.retry_after' => $seconds]);

        $this->assertSame([], app(ConversationSettings::class)->problems());
    }

    /** 既定の接続が DB の表に無いときは、会話の接続を既定の接続と比べない(既定の接続の文だけが出る) */
    public function test_the_conversation_queue_is_not_compared_with_a_default_queue_outside_the_database(): void
    {
        config(['queue.default' => 'sync']);

        $this->assertSame([], app(ConversationSettings::class)->problems());
        $this->assertSame(['既定のキューの接続(QUEUE_CONNECTION)が DB の表(database)でない'], app(PromptKinds::class)->problems());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function purposes(): array
    {
        return ['会話' => ['conversation'], '会話の題' => ['title'], 'ファイルを作る' => ['create_file']];
    }

    #[DataProvider('purposes')]
    public function test_detects_a_purpose_without_pricing(string $purpose): void
    {
        config(["ai_runs.conversation.purposes.{$purpose}.model" => 'claude-unknown']);
        $problems = app(ConversationSettings::class)->problems();

        $unpriced = "会話の用途 {$purpose} の接続先とモデルの単価が無いか、最後の段に入力の上限がある(config/ai_runs.php の pricing)";
        $this->assertSame($unpriced, $problems[0] ?? null);
        $this->assertCount($purpose === 'create_file' ? 2 : 1, $problems);
    }

    #[DataProvider('purposes')]
    public function test_detects_provider_options_with_a_key_set_for_each_run(string $purpose): void
    {
        $key = "ai_runs.conversation.purposes.{$purpose}.provider_options";
        $section = 'config/ai_runs.php の conversation';

        config([$key => ['metadata' => ['user_id' => 'fixed']]]);
        $metadata = "会話の用途 {$purpose} の provider_options が実行ごとに足す鍵 metadata を持つ({$section})";
        $this->assertSame([$metadata], app(ConversationSettings::class)->problems());

        config([$key => ['container' => 'container_1']]);
        $container = "会話の用途 {$purpose} の provider_options が作業場を指す鍵 container を持つ({$section})";
        $this->assertSame([$container], app(ConversationSettings::class)->problems());
    }

    #[DataProvider('purposes')]
    public function test_detects_a_purpose_without_a_provider(string $purpose): void
    {
        config(["ai_runs.conversation.purposes.{$purpose}.provider" => '']);

        $this->assertSame(
            ["会話の用途 {$purpose} の provider が無いか形が合わない(config/ai_runs.php の conversation.purposes)"],
            app(ConversationSettings::class)->problems(),
        );
    }

    public function test_detects_a_conversation_purpose_without_the_context_window(): void
    {
        config(['ai_runs.conversation.purposes.conversation.context_window_tokens' => null]);

        $this->assertSame(
            ['会話の用途 conversation の context_window_tokens が無いか形が合わない(config/ai_runs.php の conversation.purposes)'],
            app(ConversationSettings::class)->problems(),
        );
    }

    /** ファイルを作るの接続先は Anthropic に限る(単価とコード実行の時間単価はある接続先にして、driver だけを外す) */
    public function test_detects_a_file_creation_purpose_whose_provider_is_not_anthropic(): void
    {
        $tiers = [[
            'up_to_input_tokens' => null, 'input' => 1.0, 'output' => 1.0, 'cache_read' => 1.0, 'cache_write' => 1.0,
        ]];
        config([
            'ai.providers.other' => ['driver' => 'openai'],
            'ai_runs.pricing.other' => ['model-a' => $tiers],
            'ai_runs.code_execution_pricing.other' => ['model-a' => ['usd_per_hour' => 0.05, 'min_seconds' => 300]],
            'ai_runs.conversation.purposes.create_file.provider' => 'other',
            'ai_runs.conversation.purposes.create_file.model' => 'model-a',
        ]);

        $this->assertSame(
            ['会話の用途 create_file の接続先 other の driver が anthropic でない(config/ai.php の providers)'],
            app(ConversationSettings::class)->problems(),
        );
    }

    /** ファイルを作るのモデルはコード実行の時間単価が要る(費用を 0 と数えて上限をすり抜けないため)。ほかの用途は要らない */
    public function test_detects_a_file_creation_model_without_the_code_execution_price(): void
    {
        config(['ai_runs.conversation.purposes.create_file.model' => 'claude-haiku-5-5']);

        $this->assertSame([self::NO_CODE_EXECUTION_PRICE], app(ConversationSettings::class)->problems());

        config([
            'ai_runs.conversation.purposes.create_file.model' => 'claude-sonnet-5-5',
            'ai_runs.conversation.purposes.conversation.model' => 'claude-haiku-5-5',
        ]);
        $this->assertSame([], app(ConversationSettings::class)->problems());
    }

    /** 会話の接続の名前が無い・空は、投げずに文として返し、用途の確かめを続ける(確かめのコマンドにも両方が出る) */
    #[TestWith([null])]
    #[TestWith([''])]
    public function test_a_missing_conversation_connection_is_listed_with_the_other_problems(?string $name): void
    {
        config([
            'ai_runs.conversation.queue_connection' => $name,
            'ai_runs.conversation.purposes.create_file.model' => 'claude-haiku-5-5',
        ]);

        $this->assertListedWithEachOther([
            'config/ai_runs.php の conversation.queue_connection が空でない文字列でない',
            self::NO_CODE_EXECUTION_PRICE,
        ]);
    }

    /** コード実行の時間単価の形が合わないときも、投げずに文として返し、ほかの確かめを続ける(確かめのコマンドにも両方が出る) */
    public function test_a_malformed_code_execution_price_is_listed_with_the_other_problems(): void
    {
        config([
            'ai_runs.code_execution_pricing.anthropic.claude-sonnet-5-5' => [
                'usd_per_hour' => 0.05,
                'min_seconds' => 0,
            ],
            self::CONNECTION.'.retry_after' => 0,
        ]);

        $this->assertListedWithEachOther([
            '会話のキューの接続 database-agent の retry_after が正の整数でない(config/queue.php)',
            'config/ai_runs.php の code_execution_pricing.anthropic.claude-sonnet-5-5 の形が合わない',
        ]);
    }

    /**
     * 会話の設定の確かめが文を $messages の順に返し、確かめのコマンドが終了コード 1 でその全部を出す。
     *
     * @param  list<string>  $messages
     */
    private function assertListedWithEachOther(array $messages): void
    {
        $this->assertSame($messages, app(ConversationSettings::class)->problems());
        $this->assertSame(1, Artisan::call('ai:check-settings'));
        $this->assertSame(implode("\n", $messages)."\n", Artisan::output());
    }
}
