<?php

namespace App\Queries\Ai;

use App\Support\DefaultDatabaseQueue;
use Illuminate\Support\Facades\Config;
use LogicException;

/**
 * 会話の型の用途と会話の接続の設定(config/ai_runs.php の conversation と code_execution_pricing。docs の 設計/AI/実行と費用/接続先と費用.md 5 節の
 * 「会話の型の用途」、会話の接続 = 設計/AI/実行と費用/README.md 4.5)を読む 1 か所と、起動の時に確かめる食い違い(設計/AI/データと失敗と検査.md 8 節)。
 * 用途ごとの接続先とモデルは種類をまたいで共有するので、種類の設定({@see KindSettings})に持たない。形が合わない設定は、値を使う所では作りの誤り
 * (LogicException)、起動の時の確かめ {@see self::problems()} では食い違いの文(ほかの確かめを続ける)。
 * 単価の段の規則 = {@see AiSettings::pricesEveryInput()}。
 *
 * @phpstan-type ConversationPurpose array{
 *     provider: string, model: string, display_name: string|null, knowledge_cutoff: string|null,
 *     provider_options: array<string, mixed>
 * }
 * @phpstan-type CodeExecutionPrice array{usd_per_hour: float, min_seconds: int}
 */
class ConversationSettings
{
    /** 用途: 会話(要約も会話の用途で送る = 設計/AI/エージェントの実行/途中の表示と上限.md 9.9) */
    public const CONVERSATION = 'conversation';

    /** 用途: 会話の題 */
    public const TITLE = 'title';

    /** 用途: ファイルを作る(接続先は Anthropic に限る = 設計/AI/ファイルの作業場.md 12.1・12.2) */
    public const CREATE_FILE = 'create_file';

    /** 用途の一覧 */
    public const PURPOSES = [self::CONVERSATION, self::TITLE, self::CREATE_FILE];

    /** 用途の接続先の要求に足す指定が持たない鍵(実行ごとに足す訪問者の ID = 4.4 と、作業場を指す鍵 = 12.2。どちらも実行ごとに ③ が入れる) */
    private const RUN_OPTION_KEYS = [KindSettings::RUN_OPTION_KEY => '実行ごとに足す鍵', 'container' => '作業場を指す鍵'];

    /** ファイルを作るの接続先の driver */
    private const CREATE_FILE_DRIVER = 'anthropic';

    private const SECTION = 'config/ai_runs.php の conversation';

    /** 会話の接続の名前が無い・空のときの文 */
    private const NO_CONNECTION = self::SECTION.'.queue_connection が空でない文字列でない';

    public function __construct(private AiSettings $settings) {}

    /**
     * 会話のジョブを入れるキューの接続の名前(config/queue.php の connections の鍵)。
     *
     * @throws LogicException 無い・空のとき
     */
    public function queueConnection(): string
    {
        return $this->connectionName() ?? throw new LogicException(self::NO_CONNECTION);
    }

    /**
     * 用途の接続先・モデル・モデルの表示名と知識の期限(任意)・接続先の要求に足す指定。
     *
     * @return ConversationPurpose
     *
     * @throws LogicException 知らない用途・形が合わないとき
     */
    public function purpose(string $purpose): array
    {
        [$values, $shape] = $this->checkedPurpose($purpose);

        return $values ?? throw new LogicException(implode(' / ', $shape));
    }

    /**
     * 用途の設定と、形の食い違い(形が合えば設定と空、合わなければ null と食い違いの文。形の確かめは 1 回)。
     *
     * @return array{ConversationPurpose, list<never>}|array{null, non-empty-list<string>}
     *
     * @throws LogicException 知らない用途のとき
     */
    private function checkedPurpose(string $purpose): array
    {
        $values = $this->purposeValues($purpose);
        $shape = self::shapeProblems($purpose, $values);

        if ($shape !== []) {
            return [null, $shape];
        }

        /** @var array<string, mixed> $options */
        $options = $values['provider_options'] ?? [];

        return [[
            'provider' => (string) $values['provider'],
            'model' => (string) $values['model'],
            'display_name' => isset($values['display_name']) ? (string) $values['display_name'] : null,
            'knowledge_cutoff' => isset($values['knowledge_cutoff']) ? (string) $values['knowledge_cutoff'] : null,
            'provider_options' => $options,
        ], []];
    }

    /**
     * 会話の用途のモデルの読める量(トークン。設計/AI/エージェントの実行/途中の表示と上限.md 9.9)。
     *
     * @throws LogicException 正の整数でないとき
     */
    public function contextWindowTokens(): int
    {
        $tokens = $this->purposeValues(self::CONVERSATION)['context_window_tokens'] ?? null;

        return is_int($tokens) && $tokens > 0
            ? $tokens
            : throw new LogicException(self::SECTION.'.purposes.conversation.context_window_tokens が正の整数でない');
    }

    /**
     * 接続先・モデルのコード実行の時間単価(1 時間・1 作業場あたりの米ドルと、最小の秒。設計/AI/実行と費用/接続先と費用.md 5 節)。無ければ null。
     *
     * @return CodeExecutionPrice|null
     *
     * @throws LogicException 形が合わないとき
     */
    public function codeExecutionPrice(string $provider, string $model): ?array
    {
        $price = $this->priceOf($provider, $model);

        return is_string($price) ? throw new LogicException($price) : $price;
    }

    /**
     * 起動の時に確かめる、会話の接続と用途の食い違い(設計/AI/データと失敗と検査.md 8 節)。無ければ空。設定の値の形の誤りも投げずに文として返し、
     * ほかの確かめを続ける。
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $name = $this->connectionName();
        $problems = $name === null ? [self::NO_CONNECTION] : $this->queueProblems($name);

        foreach (self::PURPOSES as $purpose) {
            [$values, $shape] = $this->checkedPurpose($purpose);
            $problems = [...$problems, ...($values === null ? $shape : $this->purposeProblems($purpose, $values))];
        }

        return $problems;
    }

    /**
     * 会話の接続の食い違い: 既定の接続と同じく database ドライバ・既定の DB 接続・after_commit が偽。キューの名前が既定の接続と違い、ジョブの表が
     * 同じで、配り直すまでの時間が既定の接続の値以下(設計/AI/実行と費用/README.md 4.1・4.5)。既定の接続が DB の表に無ければ、比べない
     * (既定の接続の食い違いは {@see DefaultDatabaseQueue} の文が出る)。
     *
     * @return list<string>
     */
    private function queueProblems(string $name): array
    {
        $problems = [
            ...DefaultDatabaseQueue::storeProblems($name),
            ...DefaultDatabaseQueue::afterCommitProblems($name),
        ];

        if ($problems !== [] || DefaultDatabaseQueue::storeProblems() !== []) {
            return $problems;
        }

        $own = (array) Config::get("queue.connections.{$name}");
        $default = (array) Config::get('queue.connections.'.DefaultDatabaseQueue::connection());
        $label = "会話のキューの接続 {$name}";

        $sameQueue = ($own['queue'] ?? null) === ($default['queue'] ?? null);
        $otherTable = ($own['table'] ?? null) !== ($default['table'] ?? null);

        return [
            ...($sameQueue ? ["{$label} のキューの名前が既定のキューの接続と同じ(config/queue.php)"] : []),
            ...($otherTable ? ["{$label} のジョブの表が既定のキューの接続と違う(config/queue.php)"] : []),
            ...self::retryAfterProblems($label, $own['retry_after'] ?? null, $default['retry_after'] ?? null),
        ];
    }

    /**
     * 会話の接続の配り直すまでの時間が正の整数で、既定の接続の値以下か(毎日の初期化が処理中のジョブを待つ最長を延ばさないため = 4.1)。
     *
     * @return list<string>
     */
    private static function retryAfterProblems(string $label, mixed $own, mixed $default): array
    {
        if (! is_int($own) || $own <= 0) {
            return ["{$label} の retry_after が正の整数でない(config/queue.php)"];
        }

        return is_int($default) && $own > $default
            ? ["{$label} の retry_after {$own} 秒が既定のキューの接続の retry_after {$default} 秒より長い(config/queue.php)"]
            : [];
    }

    /**
     * 形の合う用途の食い違い: 単価があり最後の段に入力の上限が無い、足す指定が実行ごとに入れる鍵を持たない。ファイルを作るは、加えて接続先が
     * Anthropic で、コード実行の時間単価がある(接続先と費用.md 5 節・データと失敗と検査.md 8 節)。
     *
     * @param  ConversationPurpose  $values
     * @return list<string>
     */
    private function purposeProblems(string $purpose, array $values): array
    {
        $label = "会話の用途 {$purpose}";
        $problems = $this->settings->pricesEveryInput($values['provider'], $values['model'])
            ? []
            : ["{$label} の接続先とモデルの単価が無いか、最後の段に入力の上限がある(config/ai_runs.php の pricing)"];

        foreach (self::RUN_OPTION_KEYS as $key => $meaning) {
            $problems = array_key_exists($key, $values['provider_options'])
                ? [...$problems, "{$label} の provider_options が{$meaning} {$key} を持つ(".self::SECTION.')']
                : $problems;
        }

        return $purpose === self::CREATE_FILE ? [...$problems, ...$this->createFileProblems($values)] : $problems;
    }

    /**
     * ファイルを作るの用途の食い違い(作業場の自前の口が Anthropic の Messages API と Files API だけを呼ぶ = 設計/AI/ファイルの作業場.md 12.2)。
     *
     * @param  ConversationPurpose  $values
     * @return list<string>
     */
    private function createFileProblems(array $values): array
    {
        $driver = $this->settings->providerDriver($values['provider']);
        $label = '会話の用途 '.self::CREATE_FILE;
        $wrongDriver = "{$label} の接続先 {$values['provider']} の driver が anthropic でない(config/ai.php の providers)";
        $price = $this->priceOf($values['provider'], $values['model']);

        return [
            ...($driver !== self::CREATE_FILE_DRIVER ? [$wrongDriver] : []),
            ...match (true) {
                $price === null => ["{$label} の接続先とモデルのコード実行の時間単価が無い(config/ai_runs.php の code_execution_pricing)"],
                is_string($price) => [$price],
                default => [],
            },
        ];
    }

    /**
     * 用途の設定の形の食い違い(接続先とモデルは空でない文字列、表示名と知識の期限は空でない文字列か無し、足す指定は配列、会話の用途は読める量が
     * 正の整数)。
     *
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private static function shapeProblems(string $purpose, array $values): array
    {
        $text = static fn (mixed $value): bool => is_string($value) && $value !== '';
        $wrong = array_keys(array_filter([
            'provider' => ! $text($values['provider'] ?? null),
            'model' => ! $text($values['model'] ?? null),
            'display_name' => isset($values['display_name']) && ! $text($values['display_name']),
            'knowledge_cutoff' => isset($values['knowledge_cutoff']) && ! $text($values['knowledge_cutoff']),
            'provider_options' => ! is_array($values['provider_options'] ?? []),
            'context_window_tokens' => $purpose === self::CONVERSATION
                && ! (is_int($values['context_window_tokens'] ?? null) && $values['context_window_tokens'] > 0),
        ]));

        $keys = implode('・', $wrong);

        return $wrong === [] ? [] : ["会話の用途 {$purpose} の {$keys} が無いか形が合わない(".self::SECTION.'.purposes)'];
    }

    /**
     * 接続先・モデルのコード実行の時間単価。無ければ null、形が合わなければその文。
     *
     * @return CodeExecutionPrice|string|null
     */
    private function priceOf(string $provider, string $model): array|string|null
    {
        $prices = Config::get('ai_runs.code_execution_pricing');
        $ofProvider = is_array($prices) ? ($prices[$provider] ?? null) : null;
        $price = is_array($ofProvider) ? ($ofProvider[$model] ?? null) : null;
        $perHour = is_array($price) ? ($price['usd_per_hour'] ?? null) : null;
        $minSeconds = is_array($price) ? ($price['min_seconds'] ?? null) : null;

        return match (true) {
            $price === null => null,
            (is_int($perHour) || is_float($perHour)) && is_int($minSeconds) && $minSeconds > 0 => [
                'usd_per_hour' => (float) $perHour,
                'min_seconds' => $minSeconds,
            ],
            default => "config/ai_runs.php の code_execution_pricing.{$provider}.{$model} の形が合わない",
        };
    }

    /** 会話の接続の名前(無い・空なら null) */
    private function connectionName(): ?string
    {
        $name = Config::get('ai_runs.conversation.queue_connection');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * 用途の設定の配列(無ければ空)。
     *
     * @return array<array-key, mixed>
     */
    private function purposeValues(string $purpose): array
    {
        if (! in_array($purpose, self::PURPOSES, true)) {
            throw new LogicException("会話の型の用途 {$purpose} を知らない");
        }

        $values = Config::get("ai_runs.conversation.purposes.{$purpose}");

        return is_array($values) ? $values : [];
    }
}
