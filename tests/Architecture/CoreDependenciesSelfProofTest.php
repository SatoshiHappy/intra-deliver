<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 機械検査 (f) 会話の中核の依存(docs の 設計/AI/検査で守る結果.md の (f)、表の正本 = 設計/AI/会話の中核.md 13.1。並びと規則 =
 * tests/Architecture/Policy/CoreDependencies.php)の自己証明。13.1 の表の行(約束・移した版・代わりの版・版ごとの変換の 1 か所)ごとに、
 * 並びの外への依存(DB の型を含む)と、関数の呼び出しでファサードが前に立つ物に届くこと(式の型の規則)と、中核を参照してよい側の外からの参照
 * (tests/Architecture/Policy/CoreUsers.php)を見つけ、並びの中の依存では指摘が無いことを見る。
 */
final class CoreDependenciesSelfProofTest extends PhpStanSelfProofTestCase
{
    private const CONTRACT = 'phpat.coreContractDependencies';

    private const PORTED = 'phpat.portedCoreDependencies';

    private const SUBSTITUTE = 'phpat.substituteCoreDependencies';

    private const PORTED_CONVERSION = 'phpat.portedCoreConversionDependencies';

    private const SUBSTITUTE_CONVERSION = 'phpat.substituteCoreConversionDependencies';

    private const EXPRESSION = 'forbiddenExpressionType';

    private const CONTRACT_USERS = 'phpat.coreContractUsers';

    private const VERSION_USERS = 'phpat.coreVersionUsers';

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function violations(): array
    {
        return [
            '約束が Eloquent のモデルに依存する' => [self::CONTRACT, 'app/Actions/Ai/Core/ContractUsesModel.php', 12],
            '約束が版の名前空間に依存する' => [self::CONTRACT, 'app/Actions/Ai/Core/ContractUsesVersion.php', 14],
            '約束が版だけの 1 ステップの口の値の型に依存する' => [self::CONTRACT, 'app/Actions/Ai/Core/ContractUsesVersion.php', 15],
            '約束が PHP の標準の中の DB の型に依存する' => [self::CONTRACT, 'app/Actions/Ai/Core/ContractUsesDatabase.php', 12],
            '約束がコンテナに依存する' => [self::CONTRACT, 'app/Actions/Ai/Core/ContractUsesContainer.php', 12],
            '移した版が PDO に依存する' => [self::PORTED, 'app/Actions/Ai/Core/Ported/PortedUsesPdo.php', 12],
            '移した版が関数の呼び出しで DB に届く' => [self::EXPRESSION, 'app/Actions/Ai/Core/Ported/PortedCallsDatabase.php', 13],
            '移した版が関数の呼び出しでキューに積む' => [self::EXPRESSION, 'app/Actions/Ai/Core/Ported/PortedDispatches.php', 12],
            '移した版が関数の呼び出しで認証を読む' => [self::EXPRESSION, 'app/Actions/Ai/Core/Ported/PortedReadsUser.php', 12],
            '移した版が関数の呼び出しでセッションを読む' => [self::EXPRESSION, 'app/Actions/Ai/Core/Ported/PortedReadsSession.php', 12],
            '移した版の変換の 1 か所が HTTP を送る型に依存する' => [
                self::PORTED_CONVERSION,
                'app/Actions/Ai/Core/Ported/Sdk/PortedSends.php',
                12,
            ],
            '代わりの版の変換の 1 か所が PHP の標準の中の DB の型に依存する' => [
                self::SUBSTITUTE_CONVERSION,
                'app/Actions/Ai/Core/Substitute/Sdk/SubstituteUsesDatabase.php',
                12,
            ],
            '抑制の注釈を付けた約束の依存(消せない)' => [self::CONTRACT, 'app/Actions/Ai/Core/SuppressedContract.php', 13],
            '移した版がほかの版に依存する' => [self::PORTED, 'app/Actions/Ai/Core/Ported/PortedHelper.php', 14],
            '移した版がファサードに依存する' => [self::PORTED, 'app/Actions/Ai/Core/Ported/PortedHelper.php', 18],
            '移した版が変換の 1 か所の外で例外の型に依存する' => [self::PORTED, 'app/Actions/Ai/Core/Ported/PortedHelper.php', 21],
            '移した版の変換の 1 か所がファサードに依存する' => [
                self::PORTED_CONVERSION,
                'app/Actions/Ai/Core/Ported/Sdk/PortedConversion.php',
                14,
            ],
            'Ai の外のモジュールが中核の約束に依存する' => [self::CONTRACT_USERS, 'app/Actions/Meeting/MeetingUsesCore.php', 12],
            '繋ぎが版を選ぶ 1 か所の外で版に依存する' => [self::VERSION_USERS, 'app/Actions/Ai/Runs/RunUsesVersion.php', 12],
            '代わりの版が Ai の内部に依存する' => [self::SUBSTITUTE, 'app/Actions/Ai/Core/Substitute/SubstituteHelper.php', 12],
            '代わりの版の変換の 1 か所が Eloquent のモデルに依存する' => [
                self::SUBSTITUTE_CONVERSION,
                'app/Actions/Ai/Core/Substitute/Sdk/SubstituteConversion.php',
                12,
            ],
        ];
    }

    #[DataProvider('violations')]
    public function test_detects_a_dependency_outside_the_allowed_list(string $rule, string $file, int $line): void
    {
        $analysis = self::analyse('CoreDependencies/violating');

        $this->assertDetects($rule, 'CoreDependencies/violating/'.$file, $line, $analysis);
    }

    public function test_passes_the_dependencies_in_the_allowed_lists(): void
    {
        $this->assertClean(self::analyse('CoreDependencies/passing'));
    }
}
