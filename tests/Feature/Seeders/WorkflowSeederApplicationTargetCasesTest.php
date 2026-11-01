<?php

namespace Tests\Feature\Seeders;

use App\Enums\Records\RecordRevisionAction;
use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowCompletionReason;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use App\Models\Records\RecordRevision;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordFormQuery;
use App\Queries\Workflow\ApplicationTargetValueQuery;
use App\Queries\Workflow\CaseNumbers;
use App\Queries\Workflow\CaseShowProps;
use App\Queries\Workflow\CaseShowSelection;
use App\Queries\Workflow\RelatedCases;
use App\Queries\Workflow\WorkflowRecordHolds;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Database\Seeders\SeedingDates;
use Tests\Support\TransactionalTestCase;

/**
 * 申請対象からの申請の見本(docs の 設計/ワークフロー/申請の周辺/初期データ.md の 2 節の検査。時刻は {@see WorkflowSeederSampleTimesTest})。
 * 初期データは DatabaseSeeder が 1 回入れたもの(流し直さない)。
 *
 * @phpstan-import-type RelatedGroup from RelatedCases
 */
final class WorkflowSeederApplicationTargetCasesTest extends TransactionalTestCase
{
    private const PROJECT = '新規顧客開拓キャンペーン';

    /**
     * WF-000017 は完了(承認)、WF-000018 は課長の承認待ち。どちらも申請者 u0001・版 3・申請基準日 = 流す日。
     */
    public function test_the_two_cases_follow_the_operations_of_the_design(): void
    {
        $histories = fn (string $number) => WorkflowCaseHistory::query()->with('actor')
            ->where('workflow_case_id', $this->caseOf($number)->id)->orderBy('id')->get()
            ->map(fn (WorkflowCaseHistory $row) => [$row->action->value, $row->actor?->code, $row->comment])->all();

        $this->assertSame([
            ['apply', 'u0001', '新規顧客開拓キャンペーンの計画を申請します。'],
            ['approve', 'u0002', '内容を確認しました。'],
            ['approve', 'u0003', null],
            ['approve', 'u0009', null],
            ['approve', 'u0010', null],
            ['approve', 'u0003', null],
        ], $histories('WF-000017'));
        $this->assertSame([['apply', 'u0001', null]], $histories('WF-000018'));

        $expected = [
            'WF-000017' => [WorkflowCaseState::Completed, WorkflowCompletionReason::Approved],
            'WF-000018' => [WorkflowCaseState::Active, null],
        ];

        foreach ($expected as $number => [$state, $reason]) {
            $case = $this->caseOf($number);
            $this->assertSame(
                [$state, $reason, 'u0001', 'プロジェクト計画の承認 '.self::PROJECT, SeedingDates::BASE, 3],
                [
                    $case->state, $case->completion_reason, $case->applicant?->code, $case->name,
                    $case->base_date?->toDateString(), $case->workflowFlowVersion?->number,
                ],
                $number,
            );
        }

        $current = $this->caseOf('WF-000018')->workflowCaseNodes()->whereIn('state', ['waiting', 'reapply', 'hold']);
        $this->assertSame(['section_chief'], $current->pluck('code')->all());
    }

    /**
     * 申請書のレコードは別々で、どちらの申請対象も同じプロジェクト(データハブの親の無いレコード)。
     */
    public function test_the_applications_are_separate_records_that_point_to_the_same_project(): void
    {
        $projectId = $this->project()->id;
        $field = $this->fieldOf('project_plan_application', 'project');
        $records = [];

        foreach (['WF-000017', 'WF-000018'] as $number) {
            $case = $this->caseOf($number);
            $application = Record::query()->with('recordModule')->findOrFail($case->record_id);
            $records[] = $application->id;
            $this->assertSame(
                ['project_plan_application', null],
                [$application->recordModule?->code, $application->parent_record_id],
                $number,
            );
            $reference = RecordReference::query()->where('record_id', $application->id)
                ->where('record_field_id', $field->id)->sole();
            $this->assertSame($projectId, $reference->referenced_record_id, $number);
        }

        $this->assertCount(2, array_unique($records));
        $this->assertNotContains($projectId, $records);
    }

    /**
     * プロジェクトのレコードは書かない(版・更新日・値が作った時のまま。持ち主も付かない)。
     */
    public function test_the_project_record_is_not_written(): void
    {
        $project = $this->project();
        $revisions = RecordRevision::query()->where('record_id', $project->id)->get();
        $source = $this->editValues($project, 'u0004');

        $this->assertSame([RecordRevisionAction::Create], $revisions->pluck('action')->all());
        $this->assertSame($project->created_at?->toIso8601String(), $project->updated_at?->toIso8601String());
        $this->assertSame([], app(WorkflowRecordHolds::class)->holdsOf([$project->id]));
        $this->assertSame([
            'name' => self::PROJECT, 'department' => 'sales-1', 'manager' => 'u0003', 'status' => 'planning',
            'start_date' => '2026-11-01', 'end_date' => '2027-03-31', 'budget' => 300, 'summary' => null,
        ], $this->shown($source['values']));
        $this->assertSame([
            ['name' => '対象リスト作成', 'due_date' => '2026-11-15', 'effort' => '3', 'is_done' => false],
            ['name' => '初回訪問', 'due_date' => '2026-12-20', 'effort' => '12', 'is_done' => false],
        ], array_map(fn (array $row) => $this->shown($row['values']), $source['details']['milestones']));
    }

    /**
     * 申請書の値と明細の行は、写しの規則(設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の 10.1)を
     * そのプロジェクトと u0001 に当てた結果(写しの値の入口)と同じ。
     */
    public function test_the_application_values_are_the_result_of_the_copy_rule(): void
    {
        $context = $this->contextOf('u0001');
        $project = $this->project();
        $query = app(ApplicationTargetValueQuery::class);
        $copied = $query->valuesOf($context, $project->id, 'project_plan', null, null, null);
        $rows = fn (array $details) => array_map(
            fn (array $row) => $this->sorted($this->choiceCodes($row['values'])),
            $details['milestones'] ?? [],
        );

        foreach (['WF-000017', 'WF-000018'] as $number) {
            $application = Record::query()->findOrFail($this->caseOf($number)->record_id);
            $values = $this->editValues($application, 'u0001');

            $this->assertSame($project->id, $values['values']['project']['id'] ?? null, $number);
            $this->assertSame(
                $this->sorted($this->choiceCodes($copied['values'])),
                $this->sorted($this->choiceCodes($values['values'])),
                $number,
            );
            $this->assertSame($rows($copied['details']), $rows($values['details']), $number);
        }
    }

    /**
     * つながる申請(u0001 で詳細を開く): 2 件が互いの群に出て、ほかの見本の詳細には出ない。
     */
    public function test_the_two_cases_show_each_other_in_the_connected_applications(): void
    {
        $this->assertSame([['同じプロジェクトを申請対象にする申請', ['WF-000018']]], $this->relatedOf('WF-000017'));
        $this->assertSame([['同じプロジェクトを申請対象にする申請', ['WF-000017']]], $this->relatedOf('WF-000018'));

        $others = WorkflowCase::query()->whereRelation('applicant', 'code', 'u0001')
            ->whereBetween('case_number', [1, 16])->orderBy('case_number')->pluck('case_number');

        $this->assertCount(13, $others);

        foreach ($others as $number) {
            $headings = array_column($this->relatedOf(CaseNumbers::format((int) $number)), 0);
            $this->assertNotContains('同じプロジェクトを申請対象にする申請', $headings, "WF-{$number}");
        }
    }

    /**
     * @return list<array{string, list<string|null>}> 詳細の群の (見出し, 申請番号の並び)
     */
    private function relatedOf(string $number): array
    {
        $selection = new CaseShowSelection(true, ['related']);
        $props = app(CaseShowProps::class)->of($this->contextOf('u0001'), $this->caseOf($number)->id, $selection);
        /** @var list<RelatedGroup> $related */
        $related = $props['related'];

        return array_map(
            fn (array $group) => [$group['heading'], array_column($group['cases'], 'number')],
            $related,
        );
    }

    private function project(): Record
    {
        $module = RecordModule::query()->where('code', 'project')->sole();
        $field = $this->fieldOf('project', 'name');

        $projects = Record::query()->where('record_module_id', $module->id)->whereNull('parent_record_id')
            ->orderBy('id')->get();

        return $projects->first(fn (Record $record) => ($record->field_values[$field->id] ?? null) === self::PROJECT)
            ?? $this->fail('プロジェクトが無い');
    }

    private function fieldOf(string $moduleCode, string $fieldCode): RecordField
    {
        $module = RecordModule::query()->where('code', $moduleCode)->sole();

        return RecordField::query()->where('record_module_id', $module->id)->where('code', $fieldCode)->sole();
    }

    private function caseOf(string $number): WorkflowCase
    {
        return WorkflowCase::query()->with(['applicant', 'workflowFlowVersion'])
            ->where('case_number', (int) substr($number, 3))->sole();
    }

    /**
     * @return array{values: array<string, mixed>, details: array<string, list<array<string, mixed>>>, version: string}
     */
    private function editValues(Record $record, string $userCode): array
    {
        $moduleCode = RecordModule::query()->findOrFail($record->record_module_id)->code;
        $requester = Requester::fromAccessContext($this->contextOf($userCode));

        return app(RecordFormQuery::class)->editValues($requester, $moduleCode, $record->id, $this->today(), 'ja');
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
     * 選択の値を選択肢のコードだけにする(写しの値の入口は名前も返し、編集の初期値は返さない)。
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function choiceCodes(array $values): array
    {
        return array_map(
            fn (mixed $value) => is_array($value) && array_keys($value) === ['code', 'name'] ? $value['code'] : $value,
            $values,
        );
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function sorted(array $values): array
    {
        ksort($values);

        return $values;
    }

    private function contextOf(string $userCode): AccessContext
    {
        $userId = User::query()->where('code', $userCode)->sole()->id;

        return new AccessContext($userId, null, null, $this->today(), AccessContext::LOCALE_CODE);
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::today(AccessContext::TIMEZONE);
    }
}
