<?php

namespace Tests\Feature\Seeders;

use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowCaseState;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordReference;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordFormQuery;
use App\Queries\Workflow\ApplicationTargetSnapshot;
use App\Queries\Workflow\WorkflowRecordHolds;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Tests\Support\TransactionalTestCase;

/**
 * 見本の案件の申請対象と申請書(docs の 設計/ワークフロー/初期データ.md の 4 節の「見本の申請はどれも申請対象を持つ」。値の正本 =
 * 設計/ワークフロー/ルートの深さ/初期データ.md の 4 節と 設計/ワークフロー/案件の運用/初期データ.md の 4.3 の「レコード(作る値)」、件数 = 同じ文書の 5 節)。
 * 初期データは DatabaseSeeder が 1 回入れたもの。
 */
final class WorkflowSeederApplicationTargetsTest extends TransactionalTestCase
{
    /** 一時保存でない見本: 案件番号 => [申請者(代理のとき代理元), 申請対象の業務モジュール, 申請対象のプロジェクト名か課題名] */
    private const SAMPLES = [
        1 => ['u0001', 'project', '営業支援ツールの導入'],
        2 => ['u0001', 'project_issue', '導入先の部署が決定していない'],
        3 => ['u0001', 'project', '展示会への出展'],
        4 => ['u0001', 'project', '販促資料の刷新'],
        5 => ['u0001', 'project', '代理店向け説明会の開催'],
        6 => ['u0010', 'project_issue', '経費の入力画面が分かりにくい'],
        7 => ['u0009', 'project_issue', '人事の申請の入口が分かれている'],
        8 => ['u0001', 'project', '社内勉強会の開催'],
        9 => ['u0001', 'project', '新規顧客開拓の訪問強化'],
        10 => ['u0001', 'project', '社内研修の刷新'],
        11 => ['u0001', 'project', '海外展示会への出展'],
        12 => ['u0001', 'project', '営業資料の多言語化'],
        13 => ['u0001', 'project', '新製品の発売計画'],
        14 => ['u0001', 'project', '第二四半期の営業計画'],
        15 => ['u0010', 'project_issue', '在庫システムの表示不具合'],
        16 => ['u0001', 'project_issue', 'ログイン画面の文言修正'],
    ];

    /** データハブの業務モジュール(持ち主を持たない) */
    private const DATA_HUB_MODULES = ['project', 'project_issue', 'meeting'];

    /** 申請書の業務モジュール => [申請対象の項目, 申請対象の業務モジュール, 名前の項目] */
    private const APPLICATION_MODULES = [
        'project_plan_application' => ['project', 'project', 'name'],
        'issue_resolution_application' => ['issue', 'project_issue', 'title'],
    ];

    /** 見本を読む人(システム管理者)。テストごとに 1 回作る */
    private ?Requester $requester = null;

    /**
     * どの見本も、申請書のレコードがコンテンツの業務モジュールにあり、申請対象の項目がデータハブのレコード(親の無いもの)を指す。
     * データハブのレコードと申請書のレコードの作成者 = 申請者(代理のとき代理元)。
     */
    public function test_every_sample_application_points_to_its_application_target_in_the_data_hub(): void
    {
        $actual = [];

        foreach (self::SAMPLES as $number => $sample) {
            [$case, $application, $applicationTarget] = $this->samplePair($number);
            $nameField = self::APPLICATION_MODULES[$application->recordModule->code ?? ''][2];
            $actual[$number] = [
                $case->applicant?->code, $applicationTarget->recordModule?->code,
                $this->editValues($applicationTarget)['values'][$nameField] ?? null,
                $applicationTarget->createdByUser?->code, $application->createdByUser?->code,
                $applicationTarget->parent_record_id,
            ];
            $contentModuleId = $case->workflowFlow?->workflowContent?->record_module_id;
            $this->assertSame($contentModuleId, $application->record_module_id, "{$number}");
        }

        $expected = array_map(
            fn (array $sample) => [$sample[0], $sample[1], $sample[2], $sample[0], $sample[0], null],
            self::SAMPLES,
        );
        $this->assertSame($expected, $actual);
    }

    /**
     * データハブのレコードの値は正本の表の「レコード(作る値)」(WF-000009 = ルートの深さの 4 節、WF-000015 = 案件の運用の 4.3)。
     */
    public function test_the_application_targets_have_the_values_of_the_design(): void
    {
        $ninth = $this->editValues($this->samplePair(9)[2]);
        $fifteenth = $this->editValues($this->samplePair(15)[2]);

        $this->assertSame([
            'name' => '新規顧客開拓の訪問強化', 'department' => 'sales-1', 'manager' => 'u0003', 'status' => 'planning',
            'start_date' => '2026-12-01', 'end_date' => '2027-03-31', 'budget' => 350,
            'summary' => '見込み顧客のリストを作り、第一営業部で訪問と提案を進める。',
        ], $this->shown($ninth['values']));
        $this->assertSame([
            ['name' => '対象リスト作成', 'due_date' => '2026-11-15', 'effort' => '3', 'is_done' => false],
            ['name' => '初回訪問', 'due_date' => '2026-12-20', 'effort' => '12', 'is_done' => false],
        ], array_map(fn (array $row) => $this->shown($row['values']), $ninth['details']['milestones']));
        $this->assertSame([
            'title' => '在庫システムの表示不具合', 'project' => '経費精算の電子化', 'assignee' => 'u0010', 'priority' => 'high',
            'status' => 'open', 'due_date' => '2026-10-31', 'reported_at' => null, 'resolution' => null,
        ], $this->shown($fifteenth['values']));
    }

    /**
     * 申請書の値 = 申請対象の値を写しの規則に通した結果(WF-000005 は再申請で変えた予算と終了日を除く)。申請対象の項目は申請対象のレコード。
     * キーの集合と値が同じことを確かめる。明細の行も同じ。
     */
    public function test_the_applications_hold_the_snapshots_of_their_application_targets(): void
    {
        $applicationTargetSnapshot = app(ApplicationTargetSnapshot::class);
        /**
         * @var array<string, array{
         *     application: array<string, mixed>,
         *     applicationTarget: array<string, mixed>,
         * }> $definitions
         */
        $definitions = [];

        foreach (self::APPLICATION_MODULES as $code => [, $applicationTargetModule]) {
            $definitions[$code] = [
                'application' => $this->definition($code),
                'applicationTarget' => $this->definition($applicationTargetModule),
            ];
        }

        foreach (array_keys(self::SAMPLES) as $number) {
            [, $application, $applicationTarget] = $this->samplePair($number);
            $code = $application->recordModule->code ?? '';
            [$fieldCode] = self::APPLICATION_MODULES[$code];
            $source = $this->editValues($applicationTarget);
            $snapshot = $applicationTargetSnapshot->snapshot(
                $definitions[$code]['application'], $definitions[$code]['applicationTarget'],
                $source['values'], $source['details'], $fieldCode, $applicationTarget->id,
            );
            $values = $this->editValues($application);
            $changed = $number === 5 ? ['budget' => true, 'end_date' => true] : [];

            $this->assertSame($applicationTarget->id, $values['values'][$fieldCode]['id'] ?? null, "{$number}");
            $excluded = [$fieldCode => true, ...$changed];
            $this->assertSame(
                $this->sorted(array_diff_key($snapshot['values'], $excluded)),
                $this->sorted(array_diff_key($values['values'], $excluded)),
                "{$number}",
            );
            $snapshotDetails = array_keys($snapshot['details']);
            $this->assertEqualsCanonicalizing($snapshotDetails, array_keys($values['details']), "{$number}");

            foreach ($snapshot['details'] as $detail => $rows) {
                $actual = $values['details'][$detail];
                $this->assertSame(
                    array_map(fn (array $row) => $this->sorted($row['values']), $rows),
                    array_map(fn (array $row) => $this->sorted($row['values']), $actual),
                    "{$number} {$detail}",
                );
            }
        }
    }

    /**
     * WF-000005: 申請書の予算 350・終了日は再申請の値で、データハブのプロジェクトは申請の時の値のまま(設計/ワークフロー/初期データ.md の 4 節の「再申請の差分」)。
     */
    public function test_the_reapplication_of_the_fifth_case_changes_only_the_application(): void
    {
        [, $application, $applicationTarget] = $this->samplePair(5);

        $applied = $this->editValues($application)['values'];
        $original = $this->editValues($applicationTarget)['values'];

        $this->assertSame([350, '2027-04-30'], [$applied['budget'] ?? null, $applied['end_date'] ?? null]);
        $this->assertSame([300, '2027-03-31'], [$original['budget'] ?? null, $original['end_date'] ?? null]);
    }

    /**
     * WF-000002 の申請対象の課題の「プロジェクト」は、WF-000001 の申請対象のプロジェクト(申請どうしのつながり = 設計/ワークフロー/初期データ.md の 4 節)。
     */
    public function test_the_issue_of_the_second_case_points_to_the_project_of_the_first_case(): void
    {
        $issue = $this->editValues($this->samplePair(2)[2]);

        $this->assertSame($this->samplePair(1)[2]->id, $issue['values']['project']['id'] ?? null);
    }

    /**
     * データハブのレコード(プロジェクト・課題・会議)には持ち主が無く、未完了の案件の申請書には持ち主がある。
     */
    public function test_the_data_hub_records_have_no_holds_and_the_active_applications_have_holds(): void
    {
        $dataHub = Record::query()->whereNull('parent_record_id')
            ->whereRelation('recordModule', fn ($modules) => $modules->whereIn('code', self::DATA_HUB_MODULES))
            ->pluck('id')->map(fn (mixed $id) => (int) $id)->values()->all();
        $active = WorkflowCase::query()->where('state', WorkflowCaseState::Active)->orderBy('id')->pluck('record_id')
            ->map(fn (mixed $id) => (int) $id)->values()->all();
        $holds = app(WorkflowRecordHolds::class);

        $this->assertNotSame([], $dataHub);
        $this->assertSame([], $holds->holdsOf(array_values($dataHub)));
        $this->assertEqualsCanonicalizing($active, array_keys($holds->holdsOf(array_values($active))));
    }

    /**
     * 件数: データハブのプロジェクト 14・課題 10、申請書 = 計画の申請 11・課題の対応の申請 5(一時保存は申請書を持たない = 案件の運用/初期データ.md の 5 節)。
     * 一時保存の見本は課題の対応の承認のフローで、レコードを持たない。
     */
    public function test_counts_the_data_hub_records_and_the_applications_and_the_draft_has_no_record(): void
    {
        $modules = ['project', 'project_issue', 'project_plan_application', 'issue_resolution_application'];
        $counts = Record::query()->join('record_modules', 'record_modules.id', '=', 'records.record_module_id')
            ->whereNull('records.parent_record_id')->whereIn('record_modules.code', $modules)
            ->groupBy('record_modules.code')
            ->selectRaw('record_modules.code, count(*) as records')->pluck('records', 'code')->all();
        $draft = WorkflowCase::query()->with('workflowFlow')->where('state', WorkflowCaseState::Draft)->sole();

        $this->assertEquals(
            [
                'issue_resolution_application' => 5, 'project' => 14, 'project_issue' => 10,
                'project_plan_application' => 11,
            ],
            $counts,
        );
        $this->assertSame(['issue_resolution', null], [$draft->workflowFlow?->code, $draft->record_id]);
    }

    /**
     * 見本の案件と、その申請書のレコードと申請対象のレコード。
     *
     * @return array{WorkflowCase, Record, Record}
     */
    private function samplePair(int $number): array
    {
        $case = WorkflowCase::query()->with(['applicant', 'workflowFlow.workflowContent'])
            ->where('case_number', $number)->sole();
        $records = Record::query()->with(['recordModule', 'createdByUser']);
        $application = (clone $records)->findOrFail($case->record_id);
        $fieldId = $case->workflowFlow?->workflowContent?->target_record_field_id;
        $applicationTargetId = RecordReference::query()->where('record_id', $application->id)
            ->where('record_field_id', $fieldId)->sole()->referenced_record_id;

        return [$case, $application, $records->findOrFail($applicationTargetId)];
    }

    /**
     * 参照と選択肢の値をコード(参照の相手にコードが無ければ表示名)にした値(正本の表の書き方に合わせる)。
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function shown(array $values): array
    {
        return array_map(
            fn (mixed $value) => is_array($value) ? ($value['code'] ?? $value['label'] ?? null) : $value,
            $values,
        );
    }

    /**
     * キーの集合と値を、キーの並びによらずに比べるための、キーで並べた値。
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function sorted(array $values): array
    {
        ksort($values);

        return $values;
    }

    /**
     * @return array{values: array<string, mixed>, details: array<string, list<array<string, mixed>>>, version: string}
     */
    private function editValues(Record $record): array
    {
        $code = $record->recordModule->code ?? '';

        return app(RecordFormQuery::class)->editValues($this->requester(), $code, $record->id, $this->today(), 'ja');
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(string $moduleCode): array
    {
        $forms = app(RecordFormQuery::class);

        return $forms->definition($this->requester(), $moduleCode, RecordModuleAction::Read, $this->today());
    }

    private function requester(): Requester
    {
        if ($this->requester === null) {
            $administrator = User::query()->where('code', 'u0004')->sole()->id;
            $context = new AccessContext($administrator, null, null, $this->today(), AccessContext::LOCALE_CODE);
            $this->requester = Requester::fromAccessContext($context);
        }

        return $this->requester;
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::today(AccessContext::TIMEZONE);
    }
}
