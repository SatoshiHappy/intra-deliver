<?php

namespace Database\Seeders;

use App\Actions\Records\AddRecordField;
use App\Actions\Records\AddRecordRule;
use App\Actions\Records\CreateRecordModule;
use App\Actions\Records\UpdateRecordModule;
use App\Enums\Menu\MenuIcon;

/**
 * ワークフローの初期データの申請書の業務モジュール 2 つ(docs の 設計/ワークフロー/初期データ.md の 3 節。ADR 0025 の決定 13)を、業務データの入れ物の業務処理で作る 1 か所。
 * 項目 = 申請対象の項目(他モジュール参照の普通の宣言)と、申請対象の業務モジュールのすべての項目(明細の行の項目を含む)を同じコード・同じ型・同じ宣言で
 * ({@see RecordsSeeder::FIELDS} から作る。写しの規則に当てるため)。規則も申請対象の業務モジュールと同じ。許可とメニューのアイテムは業務モジュールを作る業務処理のまま。
 * 参照制限の既定は {@see self::ROW_ACCESS_RESTRICTED_BY_DEFAULT}(業務データの画面で申請書を見られるのは作成者と全件の参照の人 = 初期データ.md の 3 節の見え方)。
 */
final class WorkflowApplicationModules
{
    /** プロジェクト計画の申請 */
    public const PLAN = 'project_plan_application';

    /** 課題の対応の申請 */
    public const ISSUE = 'issue_resolution_application';

    /** 申請書の業務モジュールの参照制限の既定(2 つとも同じ。関係者の印の項目は持たない) */
    public const ROW_ACCESS_RESTRICTED_BY_DEFAULT = true;

    /**
     * 申請書の業務モジュール(作る順): コード => 名前・説明・アイコン・申請対象の項目のコードと名前・申請対象の業務モジュールのコード。
     *
     * @var array<string, array{
     *     name: string, description: string, icon: MenuIcon, applicationTargetField: string,
     *     applicationTargetFieldName: string, applicationTargetModule: string,
     * }>
     */
    public const MODULES = [
        self::PLAN => [
            'name' => 'プロジェクト計画の申請', 'description' => 'プロジェクトの計画の承認を求める申請書', 'icon' => MenuIcon::FileText,
            'applicationTargetField' => 'project', 'applicationTargetFieldName' => 'プロジェクト',
            'applicationTargetModule' => 'project',
        ],
        self::ISSUE => [
            'name' => '課題の対応の申請', 'description' => '課題の対応方針の承認を求める申請書', 'icon' => MenuIcon::ClipboardList,
            'applicationTargetField' => 'issue', 'applicationTargetFieldName' => '課題',
            'applicationTargetModule' => 'project_issue',
        ],
    ];

    /**
     * 申請対象の業務モジュールの宣言から替える所: 申請書の業務モジュールのコード => 項目のコード => 替える宣言。
     * 予算は分岐の条件が読むので必須(初期データ.md の 3 節の「seeder が満たす条件」)。
     */
    private const CHANGES = [
        self::PLAN => [
            'budget' => ['is_required' => true],
        ],
    ];

    public function __construct(
        private CreateRecordModule $createModule,
        private UpdateRecordModule $updateModule,
        private AddRecordField $addField,
        private AddRecordRule $addRule,
    ) {}

    /**
     * 申請書の業務モジュール 2 つと、その項目・明細の行の項目・規則を作る(RecordsSeeder の後、コンテンツの前に 1 回呼ぶ)。
     */
    public function create(): void
    {
        foreach (self::MODULES as $code => $definition) {
            $applicationTargetModule = $definition['applicationTargetModule'];
            $this->createModule->execute([
                'code' => $code, 'name' => $definition['name'], 'description' => $definition['description'],
                'icon' => $definition['icon']->value,
            ]);
            $this->updateModule->execute(
                $code,
                ['row_access_restricted_by_default' => self::ROW_ACCESS_RESTRICTED_BY_DEFAULT],
            );
            $applicationTargetField = [
                'code' => $definition['applicationTargetField'], 'name' => $definition['applicationTargetFieldName'],
                'type' => 'record',
                'referenced_module' => $applicationTargetModule,
            ];
            $fields = array_map(
                fn (array $field) => $this->declarationOf($code, $field),
                RecordsSeeder::FIELDS[$applicationTargetModule],
            );
            $this->addFields($code, [$applicationTargetField, ...$fields]);
            $this->addDetailFields($applicationTargetModule, $fields);

            foreach (RecordsSeeder::RULES[$applicationTargetModule] as $rule) {
                $this->addRule->execute($code, $rule);
            }
        }
    }

    /**
     * 申請対象の項目のコード(申請書の業務モジュールのコードで引く)。
     */
    public static function applicationTargetFieldOf(string $moduleCode): string
    {
        return self::MODULES[$moduleCode]['applicationTargetField'];
    }

    /**
     * 申請対象の業務モジュールのコード(申請書の業務モジュールのコードで引く)。
     */
    public static function applicationTargetModuleOf(string $moduleCode): string
    {
        return self::MODULES[$moduleCode]['applicationTargetModule'];
    }

    /**
     * 申請対象の業務モジュールの項目の宣言から、申請書の業務モジュールの宣言を作る(替える所 + 明細のモジュールのコード)。
     * 明細のモジュールのコードは、どの申請書・明細でも「申請書の業務モジュールのコード + `_` + 明細の項目のコード」にする
     * (特例を置かない。ほかの業務モジュールのコードと重なれば、項目を足す業務処理のコードの一意の検証で止まる)。
     *
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function declarationOf(string $moduleCode, array $field): array
    {
        $declaration = array_replace($field, self::CHANGES[$moduleCode][$field['code']] ?? []);
        if ($declaration['type'] === 'details') {
            $declaration['child_module']['code'] = "{$moduleCode}_{$declaration['code']}";
        }

        return $declaration;
    }

    /**
     * 明細の行の項目(申請対象の明細のモジュールと同じ宣言)を、申請書の明細のモジュールに足す。
     *
     * @param  array<int, array<string, mixed>>  $fields  申請書の業務モジュールの項目(申請対象の項目を除く。申請対象の業務モジュールの項目と同じ並び)
     */
    private function addDetailFields(string $applicationTargetModule, array $fields): void
    {
        foreach (RecordsSeeder::FIELDS[$applicationTargetModule] as $index => $field) {
            if ($field['type'] === 'details') {
                $childFields = RecordsSeeder::FIELDS[$field['child_module']['code']];
                $this->addFields($fields[$index]['child_module']['code'], $childFields);
            }
        }
    }

    /**
     * 項目を並びの順に足す(検索条件は型「明細」以外の全項目 = RecordsSeeder と同じ)。
     *
     * @param  array<int, array<string, mixed>>  $fields
     */
    private function addFields(string $moduleCode, array $fields): void
    {
        foreach ($fields as $sortKey => $field) {
            $searchable = $field['type'] !== 'details';
            $this->addField->execute($moduleCode, ['is_searchable' => $searchable, ...$field, 'sort_key' => $sortKey]);
        }
    }
}
