<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Architecture\Engine\ProcessResult;

/**
 * B1 層ごとに依存してよい先とモジュールどうしの依存(docs の 設計/コード構成/README.md 2 節・3 節。規則 = tests/Architecture/Policy/LayerRules.php)の自己証明。
 * 各層の「依存してよい先」の外への依存と、宣言していないモジュールへの依存が、その規則で赤になる(抑制の注釈を付けても消えない)。
 * ほかのモジュールの業務処理・読み取りの内部(Api の下でない)への依存が公開の口の規則で赤になる。許された依存だけの fixture は通る
 * (ほかのモジュールの Api の下と、移行中の一覧に載ったモジュールの内部への依存、業務処理 → ジョブ、ジョブ・コマンド → 業務処理・読み取りを含む)。
 *
 * fixture 用の設定(tests/Architecture/fixtures/Layers/phpstan.neon)は、モジュールの宣言を Alpha → なし、Beta → Alpha、Gamma → なし、
 * Delta → Alpha・Beta に、移行中の一覧を Alpha だけに差し替える。
 * 流用元 = chatpinet_2 の同名のテスト(層と fixture を intra-deliver の層で作り直し、モジュールの規則を足した)。
 */
final class LayersSelfProofTest extends PhpStanSelfProofTestCase
{
    /**
     * @return array<string, array{string, string, int}>
     */
    public static function violations(): array
    {
        return [
            '入口 → 永続化' => ['phpat.entranceDependencies', 'Http/Controllers/Web/Alpha/ReachesModels.php', 12],
            '入口 → DB' => ['phpat.entranceDependencies', 'Http/Controllers/Web/Alpha/ReachesDatabase.php', 12],
            '入口 → 起動' => ['phpat.entranceDependencies', 'Http/Controllers/Web/Alpha/ReachesProviders.php', 12],
            '入口(共通)→ 業務処理' => ['phpat.commonEntranceDependencies', 'Http/Middleware/ReachesActions.php', 12],
            '業務処理 → 入口' => ['phpat.actionsDependencies', 'Actions/Alpha/ReachesEntrance.php', 12],
            '読み取り → 業務処理' => ['phpat.queriesDependencies', 'Queries/Alpha/ReachesActions.php', 12],
            '永続化 → 読み取り' => ['phpat.modelsDependencies', 'Models/Alpha/ReachesQueries.php', 12],
            '列挙 → DB' => ['phpat.enumsDependencies', 'Enums/Alpha/ReachesDatabase.php', 12],
            '共有 → 永続化' => ['phpat.supportDependencies', 'Support/ReachesModels.php', 12],
            '共有 → composer.lock に無い名前空間' => ['phpat.supportDependencies', 'Support/ReachesOtherVendor.php', 12],
            '共有 → PDO のドライバごとの派生(DB)' => ['phpat.supportDependencies', 'Support/ReachesPdoDriver.php', 12],
            '起動 → composer.lock に無い名前空間' => ['phpat.providersDependencies', 'Providers/ReachesOtherVendor.php', 12],
            'migrations → App' => ['phpat.migrationsDependencies', 'database/migrations/2026_01_01_000000_reaches_app.php', 13],
            'factories → 業務処理' => ['phpat.factoriesDependencies', 'database/factories/ReachesActions.php', 12],
            'seeders → 入口' => ['phpat.seedersDependencies', 'database/seeders/ReachesEntrance.php', 12],
            '業務処理が読み取りの interface を実装' => ['phpat.actionsImplementation', 'Actions/Alpha/ImplementsQueryContract.php', 10],
            '業務処理が読み取りのクラスを継承' => ['phpat.actionsInheritance', 'Actions/Alpha/ExtendsQueryBase.php', 10],
            'サブディレクトリの migrations → App' => ['phpat.migrationsDependencies', 'database/migrations/alpha/2026_01_01_000001_reaches_app_in_subdirectory.php', 13],
            'seeder の中の匿名クラス → 入口' => ['phpat.seedersDependencies', 'database/seeders/AnonymousReachesEntrance.php', 16],
            'モジュール → 一覧で後ろのモジュール' => ['phpat.moduleDependenciesAlpha', 'Actions/Alpha/ReachesLaterModule.php', 12],
            'モジュール → 依存先に宣言していないモジュール' => ['phpat.moduleDependenciesGamma', 'Actions/Gamma/ReachesUndeclared.php', 12],
            '抑制の注釈を付けた 読み取り → 業務処理(消せない)' => ['phpat.queriesDependencies', 'Queries/Alpha/SuppressedReachesActions.php', 13],
            'ほかのモジュールの業務処理・読み取り → 読み取りの内部(Api の下でない)' => ['phpat.publicApiDependenciesBeta', 'Actions/Delta/ReachesInternals.php', 12],
            'ほかのモジュールの入口 → 業務処理の内部(Api の下でない)' => ['phpat.publicApiDependenciesBeta', 'Http/Controllers/Web/Delta/ReachesInternalAction.php', 12],
            'ジョブ → 永続化' => ['phpat.workerDependenciesJobs', 'Jobs/Alpha/ReachesModels.php', 12],
            'ジョブ → DB' => ['phpat.workerDependenciesJobs', 'Jobs/Alpha/ReachesDatabase.php', 12],
            'コマンド → 永続化' => ['phpat.workerDependenciesConsole', 'Console/Commands/Alpha/ReachesModels.php', 12],
            '読み取り → ジョブ' => ['phpat.queriesDependencies', 'Queries/Alpha/ReachesJobs.php', 12],
            '入口 → ジョブ' => ['phpat.entranceDependencies', 'Http/Controllers/Web/Alpha/ReachesJobs.php', 12],
            'ジョブのモジュール → 依存先に宣言していないモジュール' => ['phpat.moduleDependenciesGamma', 'Jobs/Gamma/ReachesUndeclared.php', 12],
            'ほかのモジュールのジョブ → 読み取りの内部(Api の下でない)' => ['phpat.publicApiDependenciesBeta', 'Jobs/Delta/ReachesInternals.php', 12],
        ];
    }

    #[DataProvider('violations')]
    public function test_detects_a_dependency_outside_the_allowed_targets(string $ruleId, string $file, int $line): void
    {
        $this->assertDetects($ruleId, 'Layers/violating/'.$file, $line, self::layers('violating'));
    }

    public function test_passes_dependencies_on_allowed_targets_only(): void
    {
        $this->assertClean(self::layers('passing'));
    }

    private static function layers(string $kind): ProcessResult
    {
        return self::analyse('Layers/'.$kind, 'Layers/phpstan.neon');
    }
}
