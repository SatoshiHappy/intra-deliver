<?php

namespace Tests\Feature\Seeders;

use App\Enums\Menu\WorkMenuGroup;
use App\Enums\Records\RecordModuleAction;
use App\Models\Master\User;
use App\Models\Menu\Menu;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowContent;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordFormQuery;
use App\Queries\Records\RecordModuleVisibility;
use App\Queries\Workflow\ApplicationTargetSnapshot;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Database\Seeders\WorkflowApplicationModules;
use Tests\Support\TransactionalTestCase;

/**
 * 申請書の業務モジュールとコンテンツ(docs の 設計/ワークフロー/初期データ.md の 3 節の「seeder が満たす条件」と「止め方」、6 節の
 * 「申請書の業務モジュールを非表示にする手順」)。
 * 見本の案件の申請対象と申請書は {@see WorkflowSeederApplicationTargetsTest}。初期データは DatabaseSeeder が 1 回入れたもの。
 */
final class WorkflowSeederApplicationModulesTest extends TransactionalTestCase
{
    /** 申請書の業務モジュールのコード => [申請対象の項目のコード, 申請対象の業務モジュールのコード] */
    private const APPLICATION_MODULES = [
        'project_plan_application' => ['project', 'project'],
        'issue_resolution_application' => ['issue', 'project_issue'],
    ];

    /**
     * グループ「ワークフロー」(グローバルナビ・サイトマップ)の、申請する・申請一覧の後に、申請書の業務モジュールの一覧へのアイテム
     * (docs の 設計/ワークフロー/申請の周辺/メニューと導線.md の 1.1 節)。表示名とアイコンは業務モジュールの名前とアイコン。
     */
    public function test_the_group_workflow_has_the_items_of_the_application_modules_after_the_two_initial_items(): void
    {
        $trees = ['global-nav' => WorkMenuGroup::GlobalNavigation, 'sitemap' => WorkMenuGroup::Sitemap];

        foreach ($trees as $prefix => $work) {
            $group = Menu::query()->where('code', "{$prefix}-workflow")->sole();
            $children = Menu::query()->where('parent_id', $group->id)->orderBy('sort_number')->get();

            $this->assertSame([
                "{$group->code}-workflow-flows-index", "{$group->code}-workflow-cases-index",
                "{$group->code}-records-data-index-project-plan-application",
                "{$group->code}-records-data-index-issue-resolution-application",
            ], $children->pluck('code')->all());
            $this->assertSame([0, 1, 2, 3], $children->pluck('sort_number')->all());

            $codes = [WorkflowApplicationModules::PLAN, WorkflowApplicationModules::ISSUE];

            foreach ($codes as $position => $moduleCode) {
                $item = $children->get($position + 2);
                $this->assertInstanceOf(Menu::class, $item);
                $module = RecordModule::query()->where('code', $moduleCode)->sole();

                $this->assertSame("/records/data/{$moduleCode}", $item->url);
                $this->assertSame($module->icon, $item->icon_32);
                $this->assertSame(['en' => $module->name, 'ja' => $module->name], $this->displayNames($item));
                $this->assertFalse(str_starts_with($item->code, $work->recordModuleItemPrefix()));
            }
        }
    }

    /**
     * 申請書の業務モジュールは、コンテンツを作る業務処理が非表示にする(初期データは別の手順を持たない = ADR 0031 の決定 3): 業務のアプリ
     * (グループ「業務」の 2 つの木)にアイテムが無く、ワークフローのアプリのアイテムは残る。
     */
    public function test_the_application_modules_are_hidden_from_the_work_app_and_stay_in_the_workflow_app(): void
    {
        foreach ([WorkflowApplicationModules::PLAN, WorkflowApplicationModules::ISSUE] as $moduleCode) {
            $this->assertFalse(app(RecordModuleVisibility::class)->isVisible($moduleCode), $moduleCode);

            foreach (WorkMenuGroup::cases() as $work) {
                $items = Menu::query()->where('code', 'like', $work->value.'-%')
                    ->where('url', "/records/data/{$moduleCode}");
                $this->assertSame(0, $items->count(), "{$work->value} {$moduleCode}");
            }

            $workflowItems = Menu::query()->where('code', 'like', '%-workflow-records-data-index-%')
                ->where('url', "/records/data/{$moduleCode}")->pluck('code')->sort()->values()->all();
            $slug = str_replace('_', '-', $moduleCode);
            $this->assertSame([
                "global-nav-workflow-records-data-index-{$slug}",
                "sitemap-workflow-records-data-index-{$slug}",
            ], $workflowItems);
        }
    }

    /**
     * 申請書の業務モジュールの項目 = 申請対象の項目と、申請対象の業務モジュールのすべての項目(同じコード・同じ型・同じ宣言。予算だけ必須)。
     * 写しの規則は、申請対象の業務モジュールのすべての項目と、明細のすべての行の項目を写す。
     */
    public function test_the_application_modules_copy_every_field_of_their_application_target_modules(): void
    {
        foreach (self::APPLICATION_MODULES as $code => [$applicationTargetField, $applicationTargetModule]) {
            $application = $this->definition($code);
            $applicationTarget = $this->definition($applicationTargetModule);
            $expected = [
                $applicationTargetField => $this->applicationTargetFieldDeclaration($applicationTargetModule),
                ...$this->declarations($applicationTarget),
            ];

            if ($code === 'project_plan_application') {
                $expected['budget']['isRequired'] = true;
            }

            $this->assertSame($expected, $this->declarations($application), $code);
            $snapshot = app(ApplicationTargetSnapshot::class);
            $rule = $snapshot->fields($application, $applicationTarget, $applicationTargetField);
            $this->assertSame($this->valueCodes($applicationTarget), $rule['fields'], $code);
            $this->assertSame($this->detailCodes($applicationTarget), $rule['details'], $code);
        }

        $milestones = $this->childOf($this->definition('project_plan_application'), 'milestones');
        $this->assertSame('project_plan_application_milestones', $milestones['code']);
        $this->assertSame('マイルストーン', $milestones['name']);
        $projectMilestones = $this->childOf($this->definition('project'), 'milestones');
        $this->assertSame($this->declarations($projectMilestones), $this->declarations($milestones));
    }

    /**
     * 予算は必須(分岐の条件が読む)。課題の対応の申請の「プロジェクト」は、課題の「プロジェクト」と同じコード・同じ型・同じ参照先。
     */
    /**
     * 見え方(初期データ.md の 3 節): 申請書の業務モジュールは参照制限の既定が「する」で関係者の印の項目を持たず、見本の申請書のレコードは
     * どれも参照制限ありで参照者が無い(業務の画面で見られるのは作成者 = 申請者と全件の参照の人)。プロジェクト・課題は既定「しない」。
     */
    public function test_the_application_modules_restrict_their_records_and_the_application_targets_do_not(): void
    {
        $modules = RecordModule::query()->with('recordFields')
            ->whereIn('code', [...array_keys(self::APPLICATION_MODULES), 'project', 'project_issue'])
            ->get()->keyBy('code')->all();

        foreach (array_keys(self::APPLICATION_MODULES) as $code) {
            $module = $modules[$code];
            $this->assertTrue($module->row_access_restricted_by_default, $code);
            $this->assertFalse($module->recordFields->contains('grants_row_access', true), $code);
            $records = Record::query()->where('record_module_id', $module->id)->whereNull('parent_record_id');
            $this->assertGreaterThan(0, $records->count(), $code);
            $this->assertSame(0, (clone $records)->where('row_access_restricted', false)->count(), $code);
            $this->assertSame(0, (clone $records)->whereHas('rowAccessViewers')->count(), $code);
        }

        $this->assertFalse($modules['project']->row_access_restricted_by_default);
        $this->assertFalse($modules['project_issue']->row_access_restricted_by_default);
    }

    public function test_the_budget_is_required_and_the_issue_application_has_the_project_of_the_issue(): void
    {
        $budget = $this->declarations($this->definition('project_plan_application'))['budget'];
        $project = $this->declarations($this->definition('issue_resolution_application'))['project'];
        $issueProject = $this->declarations($this->definition('project_issue'))['project'];

        $this->assertSame(['integer', true], [$budget['type'], $budget['isRequired']]);
        $this->assertSame(['record', 'project'], [$project['type'], $project['referencedModule']]);
        $this->assertSame($issueProject, $project);
    }

    /**
     * 止め方: コンテンツが指す業務モジュールの集合 = 初期データが申請書として作った業務モジュールの集合。どのコンテンツの申請対象の参照先も、
     * その集合に入らない(組み込みかどうかで見分けない)。コンテンツ 2 つの申請対象の項目(コードと参照先)。
     */
    public function test_the_contents_point_to_the_application_modules_and_never_to_their_application_targets(): void
    {
        $contents = WorkflowContent::query()->with(['recordModule', 'targetRecordField'])->orderBy('code')->get();
        $referencedIds = $contents->pluck('target_record_module_id')->all();
        $referenced = RecordModule::query()->whereIn('id', $referencedIds)->pluck('code', 'id');
        $applicationModules = array_keys(WorkflowApplicationModules::MODULES);
        $contentModules = $contents->map(fn (WorkflowContent $content) => $content->recordModule?->code)->all();

        $this->assertEqualsCanonicalizing($applicationModules, $contentModules);
        $this->assertSame([], array_values(array_intersect($applicationModules, $referenced->all())));
        $this->assertSame([
            ['issue_resolution', 'issue_resolution_application', 'issue', 'project_issue'],
            ['project_plan', 'project_plan_application', 'project', 'project'],
        ], $contents->map(fn (WorkflowContent $content) => [
            $content->code, $content->recordModule?->code, $content->targetRecordField?->code,
            $referenced[$content->target_record_module_id] ?? null,
        ])->all());
    }

    /**
     * 申請対象の項目の宣言(他モジュール参照の普通の宣言: 任意・一覧に出さない・検索条件にする)。
     *
     * @return array<string, mixed>
     */
    private function applicationTargetFieldDeclaration(string $applicationTargetModule): array
    {
        return [
            'type' => 'record', 'isRequired' => false, 'isMultiline' => false, 'isMultiple' => false,
            'maxLength' => null, 'defaultValue' => null, 'options' => [],
            'referencedModule' => $applicationTargetModule,
            'isListed' => false, 'isSearchable' => true, 'isTitle' => false, 'grantsRowAccess' => false,
        ];
    }

    /**
     * 項目のコード → 宣言(名前と明細のモジュールを除く。参照先はコード)。
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, array<string, mixed>>
     */
    private function declarations(array $definition): array
    {
        $declarations = [];

        foreach ($this->fieldsOf($definition) as $field) {
            $declaration = array_diff_key($field, ['code' => true, 'name' => true, 'childModule' => true]);
            $declaration['referencedModule'] = $field['referencedModule']['code'] ?? null;
            $declarations[$field['code']] = $declaration;
        }

        return $declarations;
    }

    /**
     * 型「明細」でない項目のコード(並びの順)。
     *
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    private function valueCodes(array $definition): array
    {
        $values = array_filter($this->fieldsOf($definition), fn (array $field) => $field['type'] !== 'details');

        return array_values(array_map(fn (array $field) => $field['code'], $values));
    }

    /**
     * 明細の項目のコード → 行の項目のコード。
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, list<string>>
     */
    private function detailCodes(array $definition): array
    {
        $details = [];

        foreach ($this->fieldsOf($definition) as $field) {
            if ($field['type'] === 'details') {
                $details[$field['code']] = $this->valueCodes($field['childModule']);
            }
        }

        return $details;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function childOf(array $definition, string $code): array
    {
        $fields = array_filter($this->fieldsOf($definition), fn (array $field) => $field['code'] === $code);
        $fields = array_values($fields);

        return $fields[0]['childModule'];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<array<string, mixed>>
     */
    private function fieldsOf(array $definition): array
    {
        /** @var list<array<string, mixed>> */
        return $definition['fields'];
    }

    /**
     * @return array<string, string>
     */
    private function displayNames(Menu $item): array
    {
        $names = $item->menuTranslations()->with('locale')->get();

        return $names->mapWithKeys(fn ($name) => [(string) $name->locale?->code => $name->display_name])
            ->sortKeys()->all();
    }

    /**
     * 業務モジュールの定義(RecordFormQuery::definition の形。システム管理者の read で引く)。
     *
     * @return array<string, mixed>
     */
    private function definition(string $moduleCode): array
    {
        $today = CarbonImmutable::today(AccessContext::TIMEZONE);
        $administrator = User::query()->where('code', 'u0004')->sole()->id;
        $context = new AccessContext($administrator, null, null, $today, AccessContext::LOCALE_CODE);
        $requester = Requester::fromAccessContext($context);

        return app(RecordFormQuery::class)->definition($requester, $moduleCode, RecordModuleAction::Read, $today);
    }
}
