<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Architecture\Engine\Finding;
use Tests\Architecture\Engine\ModuleOrder;
use Tests\Architecture\Policy\Modules;
use UnexpectedValueException;

/**
 * モジュールの依存の宣言の順序(docs の 設計/コード構成/README.md 3 節「依存先に書けるのは、一覧で自分より前にあるモジュールだけ」)の
 * 照合と自己証明。
 * 違反の例 = 後ろのモジュールへの依存・自分自身への依存・宣言に無いモジュールへの依存。移行中の一覧(公開の口の規則の例外)は減らすだけ。
 */
final class ModulesTest extends TestCase
{
    /**
     * 移行中の一覧の上限(公開の口の規則を足した段 2 の区切り 1 の時点の一覧 = docs の 設計/コード構成/README.md 2 節)。一覧は減らすだけで、
     * ここに無いモジュールを足すと失敗する(上限そのものを増やさない)。
     */
    private const TRANSITIONAL_CEILING = ['Master', 'Authz', 'Menu', 'Account'];

    public function test_the_transitional_list_only_shrinks_and_names_declared_modules(): void
    {
        $this->assertSame([], array_values(array_diff(Modules::TRANSITIONAL, self::TRANSITIONAL_CEILING)), '移行中の一覧にモジュールを足した');
        $this->assertSame([], array_values(array_diff(Modules::TRANSITIONAL, Modules::names())), '移行中の一覧に宣言に無いモジュールがある');
    }

    public function test_the_declaration_depends_only_on_earlier_modules(): void
    {
        $this->assertSame([], self::describe(ModuleOrder::findings(Modules::DECLARATION)));
    }

    /**
     * 通知のモジュールは共通マスタだけに依存し、送り手と移り先の実装になるワークフロー・業務データの入れ物より前に置く
     * (docs の 設計/ワークフロー/案件の運用/通知.md の 1 節。後ろのモジュールには依存の順序の照合が依存させない)。
     */
    public function test_notice_depends_only_on_master_and_precedes_records_and_workflow(): void
    {
        $names = Modules::names();

        $this->assertSame(['Master'], Modules::DECLARATION['Notice']);
        $this->assertLessThan(array_search('Records', $names, true), array_search('Notice', $names, true));
        $this->assertLessThan(array_search('Workflow', $names, true), array_search('Notice', $names, true));
    }

    /**
     * アップロードの台帳のモジュールは共通マスタだけに依存して持ち主を知らず、添付を持つ業務データの入れ物・ワークフローより前に置き、
     * その 2 つが依存する(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の 1 節)。
     */
    public function test_upload_depends_only_on_master_and_is_used_by_records_and_workflow(): void
    {
        $names = Modules::names();

        $this->assertSame(['Master'], Modules::DECLARATION['Upload']);
        $this->assertLessThan(array_search('Records', $names, true), array_search('Upload', $names, true));
        $this->assertContains('Upload', Modules::DECLARATION['Records']);
        $this->assertContains('Upload', Modules::DECLARATION['Workflow']);
    }

    /**
     * @return array<string, array{array<string, list<string>>, list<string>}>
     */
    public static function violatingDeclarations(): array
    {
        return [
            '後ろのモジュールへの依存' => [['Alpha' => ['Beta'], 'Beta' => []], ['modules.notEarlier Alpha → Beta']],
            '自分自身への依存' => [['Alpha' => ['Alpha']], ['modules.notEarlier Alpha → Alpha']],
            '宣言に無いモジュールへの依存' => [['Alpha' => [], 'Beta' => ['Gamma']], ['modules.undeclared Beta → Gamma']],
        ];
    }

    /**
     * @param  array<string, list<string>>  $declaration
     * @param  list<string>  $expected
     */
    #[DataProvider('violatingDeclarations')]
    public function test_detects_a_dependency_that_is_not_earlier(array $declaration, array $expected): void
    {
        $this->assertSame($expected, self::describe(ModuleOrder::findings($declaration)));
    }

    public function test_passes_dependencies_on_earlier_modules(): void
    {
        $declaration = ['Alpha' => [], 'Beta' => ['Alpha'], 'Gamma' => ['Alpha', 'Beta']];

        $this->assertSame([], self::describe(ModuleOrder::findings($declaration)));
    }

    public function test_rejects_a_malformed_declaration(): void
    {
        $this->expectException(UnexpectedValueException::class);

        ModuleOrder::findings(['Alpha' => 'Beta']);
    }

    /**
     * @param  list<Finding>  $findings
     * @return list<string>
     */
    private static function describe(array $findings): array
    {
        return array_map(fn (Finding $finding): string => $finding->ruleId.' '.$finding->file, $findings);
    }
}
