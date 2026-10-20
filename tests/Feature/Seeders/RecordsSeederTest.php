<?php

namespace Tests\Feature\Seeders;

use App\Enums\Records\RecordFieldType;
use App\Models\Authz\AuthzPolicy;
use App\Models\Menu\Menu;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use App\Models\Records\RecordRule;
use Carbon\CarbonImmutable;
use Tests\Support\TransactionalTestCase;

/**
 * 業務データの入れ物の初期データ(docs の 設計/業務データの入れ物/認可とメニュー/メニューと初期データ.md 7 節・8 節の「初期データ」の件数): 業務モジュール 3 つ(明細 1)、
 * 項目 21・規則 2、レコード(プロジェクト 3・マイルストーン 7・課題 5)。型は 11 種すべてが 1 つ以上ある。
 * レコードの件数・参照・作成者は、ワークフローの初期データの案件の見本が申請で作る分(プロジェクト 5・マイルストーン 3・課題 3 = 設計/ワークフロー/初期データ.md 4 節・8 節)を含む。
 * ポリシーの件数(37 件 = 業務のルート 8 本 × 2・セット 5(全件の参照を含む)・業務モジュール 2 つ × 8)は AuthorizationSeedersTest。
 */
final class RecordsSeederTest extends TransactionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
    }

    public function test_seeds_the_modules_fields_and_rules_of_the_design(): void
    {
        $modules = RecordModule::query()->with('parentRecordModule')->orderBy('id')->get()
            ->map(fn (RecordModule $module) => [$module->code, $module->name, $module->parentRecordModule?->code])->all();
        $fieldCounts = RecordField::query()->join('record_modules', 'record_modules.id', '=', 'record_fields.record_module_id')
            ->groupBy('record_modules.code')->selectRaw('record_modules.code, count(*) as fields')->pluck('fields', 'code')->all();

        $this->assertSame([['project', 'プロジェクト', null], ['project_issue', '課題', null], ['project_milestone', 'マイルストーン', 'project']], $modules);
        $this->assertEquals(['project' => 9, 'project_milestone' => 4, 'project_issue' => 8], $fieldCounts);
        $this->assertSame(2, RecordRule::query()->count());
        $this->assertEqualsCanonicalizing(
            array_map(fn (RecordFieldType $type) => $type->value, RecordFieldType::cases()),
            RecordField::query()->distinct()->pluck('type')->map(fn (RecordFieldType $type) => $type->value)->all(),
        );
    }

    public function test_no_field_name_is_duplicated_within_a_module_and_its_details_modules(): void
    {
        $modules = RecordModule::query()->get();
        $names = [];

        foreach (RecordField::query()->get(['record_module_id', 'name']) as $field) {
            $module = $modules->firstOrFail('id', $field->record_module_id);
            $names[$module->parent_record_module_id ?? $module->id][] = $field->name;
        }

        $this->assertNotEmpty($names);

        foreach ($names as $topNames) {
            $this->assertSame($topNames, array_values(array_unique($topNames)));
        }
    }

    public function test_makes_every_field_except_details_a_search_condition(): void
    {
        $this->assertSame([], RecordField::query()->where('type', '<>', RecordFieldType::Details)->where('is_searchable', false)->pluck('code')->all());
        $this->assertSame(0, RecordField::query()->where('type', RecordFieldType::Details)->where('is_searchable', true)->count());
    }

    public function test_gives_the_work_menu_items_the_icon_of_each_module(): void
    {
        $icons = Menu::query()->where('code', 'like', '%-main-records-%')->orderBy('code')->pluck('icon_32', 'code')->all();

        $this->assertSame([
            'global-nav-main-records-project' => 'folder-kanban',
            'global-nav-main-records-project_issue' => 'list-todo',
            'sitemap-main-records-project' => 'folder-kanban',
            'sitemap-main-records-project_issue' => 'list-todo',
        ], $icons);
    }

    public function test_seeds_the_records_of_the_design(): void
    {
        $counts = Record::query()->join('record_modules', 'record_modules.id', '=', 'records.record_module_id')
            ->groupBy('record_modules.code')->selectRaw('record_modules.code, count(*) as records')->pluck('records', 'code')->all();

        // 業務データの入れ物の分 + ワークフローの案件の見本の分(段 2 の見本 = プロジェクト 5・マイルストーン 3・課題 3、版 3 の見本 = プロジェクト 4・マイルストーン 2
        // = ルートの深さ/初期データ.md 4 節)
        $this->assertEquals(['project' => 3 + 5 + 4, 'project_milestone' => 7 + 3 + 2, 'project_issue' => 5 + 3], $counts);
        // 参照の値 = プロジェクトの担当部署と責任者 12 × 2、課題のプロジェクト 8 と担当者 7(業務データの入れ物の 1 件は担当者なし)
        $this->assertSame(12 * 2 + 8 + 7, RecordReference::query()->count());
    }

    /**
     * 業務データの入れ物の初期データ(システム管理者が作ったもの)のプロジェクトは、どれも課題から参照される(ワークフローの見本のプロジェクトは見ない)。
     */
    public function test_every_project_is_referenced_by_an_issue(): void
    {
        $projects = RecordModule::query()->where('code', 'project')->sole();

        $this->assertSame(
            Record::query()->where('record_module_id', $projects->id)->whereRelation('createdByUser', 'code', 'u0004')->orderBy('id')->pluck('id')->all(),
            RecordReference::query()->where('referenced_record_module_id', $projects->id)->whereRelation('record.createdByUser', 'code', 'u0004')->distinct()->orderBy('referenced_record_id')->pluck('referenced_record_id')->all(),
        );
    }

    /**
     * 作成者 = 業務データの入れ物の分はシステム管理者(u0004)、ワークフローの見本の分は申請者(u0001・u0010・u0009。作った順)。
     */
    public function test_the_records_are_written_by_the_system_administrator_or_the_applicants_and_the_seeded_policies_are_permits(): void
    {
        $this->assertSame(['u0004', 'u0001', 'u0010', 'u0009'], Record::query()->with('createdByUser')->orderBy('id')->get()->map(fn (Record $record) => $record->createdByUser?->code)->unique()->values()->all());
        $this->assertSame(0, AuthzPolicy::query()->where('effect', '<>', 'permit')->count());
    }
}
