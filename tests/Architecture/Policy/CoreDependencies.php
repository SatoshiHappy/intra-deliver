<?php

namespace Tests\Architecture\Policy;

use LogicException;
use PHPat\Selector\Selector;
use PHPat\Selector\SelectorInterface;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * 機械検査 (f) 会話の中核の依存(docs の 設計/AI/検査で守る結果.md の (f)、表の正本 = 設計/AI/会話の中核.md 13.1。ADR 0049)。
 * 13.1 の表の行(約束・2 つの版・版ごとの変換の 1 か所)ごとに、依れる物を名前空間とクラスの単位で並べた許可の並びを持ち、各行の物は
 * その行の並び(と PHP の標準)だけを参照してよい(canOnly)。業務処理・Eloquent・DB はどの並びにも無く、DB の型(層の規則の DB の並び)は
 * どの並びからも除く。関数の呼び出し(`app('db')` など)は phpat が数えないので、式の型の規則(phpstan.neon の forbiddenExpressionTypes の
 * 中核の組)が止める。規則は抑制の注釈では消せない。
 * 並びに足すときは理由を書く。公開の写しの CI は約束と代わりの版に掛かり、移した版は手元で束ねる前に同じ検査を流す。
 */
final class CoreDependencies
{
    /** 中核の約束の名前空間(版の名前空間を除いた物) */
    public const CONTRACT = 'App\Actions\Ai\Core';

    /**
     * 版の名前空間(移した版 = 手元のリポジトリ、代わりの版 = 公開)。中の Sdk が版ごとの変換の 1 か所。
     *
     * @var list<non-empty-string>
     */
    public const VERSIONS = ['App\Actions\Ai\Core\Ported', 'App\Actions\Ai\Core\Substitute'];

    /** 版ごとの変換の 1 か所の名前空間の末尾 */
    public const CONVERSION = 'Sdk';

    /**
     * 約束が依れる物(13.1 の約束の行)。
     *
     * @var list<array{class?: non-empty-string, namespace?: non-empty-string, reason: string}>
     */
    public const CONTRACT_ALLOWED = [
        ['class' => 'App\Actions\Ai\Api\ConversationItem', 'reason' => '会話の保存の項目の型(設計/AI/エージェントの実行/口と契約.md 9.13)'],
        ['class' => 'App\Actions\Ai\Api\ConversationItemShape', 'reason' => '会話の保存の項目の形の確かめ(項目の型の部品)'],
        ['class' => 'App\Enums\Ai\ConversationItemType', 'reason' => '会話の保存の項目の種類の列挙(設計/AI/エージェントの実行/口と契約.md 9.13)'],
        [
            'class' => 'App\Enums\Ai\ConversationItemDisplay',
            'reason' => '会話の保存の項目の出し方の列挙(設計/AI/エージェントの実行/口と契約.md 9.13)',
        ],
        [
            'class' => 'App\Enums\Ai\AiInstructionTemplate',
            'reason' => '会話の保存の項目が持つ定型の種類の列挙(設計/AI/エージェントの実行/口と契約.md 9.13・設計/AI/設定とプロンプト/README.md 11.4)',
        ],
        ['class' => 'Laravel\Ai\Contracts\Tool', 'reason' => '渡す道具を 1 ステップの口へ渡す道具の約束(13.1)'],
        ['class' => 'Laravel\Ai\Contracts\Providers\TextProvider', 'reason' => '接続先の型(開けない値として受け渡す = 13.1)'],
        ['namespace' => 'Illuminate\JsonSchema', 'reason' => '構造化の出力の形の型'],
        ['namespace' => 'Illuminate\Contracts\JsonSchema', 'reason' => '道具の約束の形の口の型(laravel/ai の道具の約束が使う)'],
        ['namespace' => 'Illuminate\Contracts\Support', 'reason' => '値の型の約束(配列と JSON への写し)'],
    ];

    /**
     * 版が約束の並びに加えて依れる物(13.1 の移した版・代わりの版の行)。illuminate/support はファサードを除く(VERSION_DENIED)。
     *
     * @var list<array{class?: non-empty-string, namespace?: non-empty-string, reason: string}>
     */
    public const VERSION_ALLOWED = [
        ['class' => 'Laravel\Ai\Contracts\Gateway\StepTextGateway', 'reason' => '1 ステップの口'],
        ['class' => 'Laravel\Ai\Gateway\StepContext', 'reason' => '1 ステップの口の値の型'],
        ['class' => 'Laravel\Ai\Gateway\StepResponse', 'reason' => '1 ステップの口の値の型'],
        ['class' => 'Laravel\Ai\Gateway\TextGenerationOptions', 'reason' => '1 ステップの口の値の型'],
        ['namespace' => 'Laravel\Ai\Messages', 'reason' => '1 ステップの口に渡すメッセージの値の型'],
        ['namespace' => 'Laravel\Ai\Responses\Data', 'reason' => '1 ステップの口が返す値の型(道具の呼び出し・使用量・終わりの理由)'],
        ['namespace' => 'Illuminate\Support', 'reason' => 'illuminate/support(ファサードを除く)'],
        ['class' => 'Laravel\Ai\Attributes\CacheInstructions', 'reason' => '指示をキャッシュの指定で送る値の型(13.6 の性質)'],
        ['class' => 'Laravel\Ai\Attributes\CacheToolDefinitions', 'reason' => '道具の定義をキャッシュの指定で送る値の型(13.6 の性質)'],
        ['class' => 'Laravel\Ai\Tools\ToolNameResolver', 'reason' => '渡す道具の名前を SDK が送る名前と同じ引き方で引く(13.1)'],
    ];

    /**
     * 版が依れない物(VERSION_ALLOWED の名前空間の中で除く物)。
     *
     * @var list<array{namespace: non-empty-string, reason: string}>
     */
    public const VERSION_DENIED = [
        ['namespace' => 'Illuminate\Support\Facades', 'reason' => 'ファサードはコンテナを通してシステムの型に届く(13.1)'],
    ];

    /**
     * 変換の 1 か所が版の並びに加えて依れる物(13.1 の変換の項。接続先の誤りと流れの事象を読むのはここだけ)。
     *
     * @var list<array{class?: non-empty-string, namespace?: non-empty-string, reason: string}>
     */
    public const CONVERSION_ALLOWED = [
        ['class' => 'Illuminate\Http\Client\HttpClientException', 'reason' => 'HTTP の例外の型(誤りの状態・本文を読む)'],
        ['class' => 'Illuminate\Http\Client\RequestException', 'reason' => 'HTTP の例外の型(誤りの状態・本文を読む)'],
        ['class' => 'Illuminate\Http\Client\ConnectionException', 'reason' => 'HTTP の接続の例外の型'],
        ['class' => 'Illuminate\Http\Client\Response', 'reason' => 'HTTP の応答の型(誤りの状態・見出し・本文を読む)'],
        ['namespace' => 'Laravel\Ai\Exceptions', 'reason' => 'laravel/ai の例外の型'],
        ['namespace' => 'Laravel\Ai\Streaming\Events', 'reason' => '流れの事象の型'],
        [
            'class' => 'GuzzleHttp\Psr7\Exception\TimeoutException',
            'reason' => '流れを読む途中の時間切れの例外(設計/AI/会話の中核の口と受け手.md 13.3)',
        ],
    ];

    #[TestRule]
    public function coreContractDependencies(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::CONTRACT))
            ->excluding(...self::versionSelectors())
            ->canOnly()->dependOn()
            ->classes(
                Selector::isStandardClass(),
                Selector::inNamespace(self::CONTRACT),
                ...self::selectors(self::CONTRACT_ALLOWED),
            )
            ->excluding(...self::versionSelectors(), ...self::database())
            ->because('機械検査 (f): 中核の約束が依れるのは 13.1 の約束の行の並び(CoreDependencies::CONTRACT_ALLOWED)と PHP の標準だけ')
            ->nonIgnorable();
    }

    #[TestRule]
    public function portedCoreDependencies(): Rule
    {
        return self::versionRule(self::VERSIONS[0]);
    }

    #[TestRule]
    public function substituteCoreDependencies(): Rule
    {
        return self::versionRule(self::VERSIONS[1]);
    }

    #[TestRule]
    public function portedCoreConversionDependencies(): Rule
    {
        return self::conversionRule(self::VERSIONS[0]);
    }

    #[TestRule]
    public function substituteCoreConversionDependencies(): Rule
    {
        return self::conversionRule(self::VERSIONS[1]);
    }

    /**
     * 版(変換の 1 か所を除く)の規則: 約束・その版・版の並びだけ。
     *
     * @param  non-empty-string  $version
     */
    private static function versionRule(string $version): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace($version))
            ->excluding(Selector::inNamespace(self::conversionOf($version)))
            ->canOnly()->dependOn()
            ->classes(...self::versionTargets($version))
            ->excluding(...self::versionExcluded($version))
            ->because('機械検査 (f): 中核の版が依れるのは 13.1 の版の行の並び(CoreDependencies::VERSION_ALLOWED)と約束とその版だけ')
            ->nonIgnorable();
    }

    /**
     * 変換の 1 か所の規則: 版の並びに変換の並びを足した物だけ。
     *
     * @param  non-empty-string  $version
     */
    private static function conversionRule(string $version): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::conversionOf($version)))
            ->canOnly()->dependOn()
            ->classes(...self::versionTargets($version), ...self::selectors(self::CONVERSION_ALLOWED))
            ->excluding(...self::versionExcluded($version))
            ->because('機械検査 (f): 版の変換の 1 か所が依れるのは版の並びと 13.1 の変換の並び(CoreDependencies::CONVERSION_ALLOWED)だけ')
            ->nonIgnorable();
    }

    /**
     * 版が依れる物(約束の名前空間を含む。ほかの版は excluding で除く)。
     *
     * @param  non-empty-string  $version
     * @return list<SelectorInterface>
     */
    private static function versionTargets(string $version): array
    {
        return [
            Selector::isStandardClass(),
            Selector::inNamespace(self::CONTRACT),
            Selector::inNamespace($version),
            ...self::selectors(self::CONTRACT_ALLOWED),
            ...self::selectors(self::VERSION_ALLOWED),
        ];
    }

    /**
     * 版と変換の 1 か所の規則が依存先から除く物(ほかの版・ファサード・DB)。
     *
     * @param  non-empty-string  $version
     * @return list<SelectorInterface>
     */
    private static function versionExcluded(string $version): array
    {
        return [
            ...self::otherVersionSelectors($version),
            ...self::selectors(self::VERSION_DENIED),
            ...self::database(),
        ];
    }

    /**
     * DB(層の規則の DB の並び。PHP の標準の中の PDO の系を含む)。どの並びからも除く。
     *
     * @return list<SelectorInterface>
     */
    private static function database(): array
    {
        return [
            ...array_map(Selector::inNamespace(...), LayerRules::DATABASE_NAMESPACES),
            ...array_map(Selector::classname(...), LayerRules::DATABASE_CLASSES),
        ];
    }

    /**
     * @param  non-empty-string  $version
     * @return non-empty-string
     */
    private static function conversionOf(string $version): string
    {
        return $version.'\\'.self::CONVERSION;
    }

    /**
     * @return list<SelectorInterface>
     */
    private static function versionSelectors(): array
    {
        return array_map(fn (string $version): SelectorInterface => Selector::inNamespace($version), self::VERSIONS);
    }

    /**
     * @param  non-empty-string  $version
     * @return list<SelectorInterface>
     */
    private static function otherVersionSelectors(string $version): array
    {
        $others = array_values(array_diff(self::VERSIONS, [$version]));

        return array_map(fn (string $other): SelectorInterface => Selector::inNamespace($other), $others);
    }

    /**
     * @param  list<array{class?: non-empty-string, namespace?: non-empty-string, reason: string}>  $entries
     * @return list<SelectorInterface>
     */
    private static function selectors(array $entries): array
    {
        return array_map(fn (array $entry): SelectorInterface => self::selector($entry), $entries);
    }

    /**
     * 並びの 1 行の選び方(クラスの名前か名前空間)。
     *
     * @param  array{class?: non-empty-string, namespace?: non-empty-string, reason: string}  $entry
     *
     * @throws LogicException クラスの名前も名前空間も無い行(作りの誤り)
     */
    private static function selector(array $entry): SelectorInterface
    {
        if (isset($entry['class'])) {
            return Selector::classname($entry['class']);
        }

        if (isset($entry['namespace'])) {
            return Selector::inNamespace($entry['namespace']);
        }

        throw new LogicException('機械検査 (f) の並びの行にクラスの名前も名前空間も無い: '.$entry['reason']);
    }
}
