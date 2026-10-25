<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowCompletionReason;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Workflow\CaseNumbers;
use App\Queries\Workflow\WorkflowApplicationTargetSection;
use App\Queries\Workflow\WorkflowPeople;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BuildsRelatedCases;
use Tests\Support\TransactionalTestCase;

/**
 * 業務レコードの詳細の節「このレコードを申請対象にする申請」(docs の
 * 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の 9.4 節と 11 節の 9.4、形 =
 * 設計/業務データの入れ物/レコードの共通機能/詳細の画面/節の口.md の 1 節): 申請対象の参照先の業務モジュールのレコードだけに出て(0 件でも出る)、
 * 閲覧できない案件と一時保存は行にも件数にも入らない。申請書のレコードと、どのコンテンツの申請対象の参照先でもない業務モジュールのレコードには出ない。
 * 業務データ: projects(plan と plan2 の申請対象の参照先)・memos(どれの参照先でもない)、申請書: m_plan・m_plan2(target → projects)。
 */
final class WorkflowApplicationTargetSectionTest extends TransactionalTestCase
{
    use BuildsRelatedCases;

    private User $applicant;

    private User $stranger;

    private WorkflowFlow $plan;

    private WorkflowFlow $plan2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        [$this->applicant, $this->stranger] = $this->seededUsers('u0001', 'u0004');
        $everything = RecordModuleAction::cases();
        $projects = $this->recordModule('projects', $everything);
        $this->recordModule('memos', $everything);
        $this->plan = $this->targetFlow('plan', $projects, $everything, [$this->applicant, $this->stranger]);
        $this->plan2 = $this->targetFlow('plan2', $projects, $everything, [$this->applicant]);
    }

    /**
     * 行 = 閲覧できる一時保存でない案件を申請日時の新しい順(どのコンテンツの申請対象の項目からも)。件数は presence と中身で同じ。
     * 列 = 申請名(リンク = 申請の詳細)・申請番号・フロー・申請者・申請日・状態の札。
     */
    public function test_the_rows_are_the_visible_applications_for_the_record_newest_first(): void
    {
        $project = $this->recordIn('projects');
        $older = $this->appliedCase(
            $this->plan, $this->formApplyingTo('m_plan', $project), $this->applicant, true, '2026-10-01 09:00:00',
        );
        $older->update(['completion_reason' => WorkflowCompletionReason::Rejected]);
        $newer = $this->appliedCase(
            $this->plan2, $this->formApplyingTo('m_plan2', $project), $this->applicant, false, '2026-10-05 01:00:00',
        );
        $this->appliedCase($this->plan, $this->formApplyingTo('m_plan', $project), $this->stranger);
        $this->appliedCase($this->plan, $this->formApplyingTo('m_plan', $this->recordIn('projects')), $this->applicant);
        WorkflowCase::factory()->draft()
            ->create(['workflow_flow_id' => $this->plan->id, 'user_id' => $this->applicant->id]);

        $section = $this->section('projects', $project);

        $this->assertSame(['count' => 2], $this->presence('projects', $project));
        $columns = ['name', 'number', 'flow', 'applicant', 'applied-on', 'state'];
        $this->assertSame([2, $columns, 'このレコードを申請対象にする申請はありません。'], [
            $section['count'] ?? null, array_column($section['columns'] ?? [], 'code'), $section['emptyText'] ?? null,
        ]);
        $this->assertSame([
            $this->rowOf($newer, 'plan2 の名前', '2026-10-05', ['承認待ち', 'pending']),
            $this->rowOf($older, 'plan の名前', '2026-10-01', ['完了(否認)', 'rejected']),
        ], $section['rows'] ?? null);
    }

    /**
     * 申請対象にする申請が無い・閲覧できる案件が無いレコードにも、件数 0 で出る。
     */
    public function test_a_record_without_visible_applications_has_the_section_with_no_rows(): void
    {
        $project = $this->recordIn('projects');
        $this->appliedCase($this->plan, $this->formApplyingTo('m_plan', $project), $this->stranger);

        foreach ([$project, $this->recordIn('projects')] as $record) {
            $this->assertSame(['count' => 0], $this->presence('projects', $record));
            $section = $this->section('projects', $record);
            $this->assertSame([0, []], [$section['count'] ?? null, $section['rows'] ?? null]);
        }
    }

    /**
     * 申請書のレコードと、どのコンテンツの申請対象の参照先でもない業務モジュールのレコードには出ない。
     */
    public function test_an_application_record_and_a_record_no_content_targets_have_no_section(): void
    {
        $form = $this->formApplyingTo('m_plan', $this->recordIn('projects'));
        $this->appliedCase($this->plan, $form, $this->applicant);
        $memo = $this->recordIn('memos');
        $sections = app(WorkflowApplicationTargetSection::class);

        foreach ([['m_plan', $form], ['memos', $memo]] as [$moduleCode, $record]) {
            $this->assertNull($sections->presence($this->contextFor($this->applicant->id), $moduleCode, $record->id));
            $this->assertNull($sections->section($this->contextFor($this->applicant->id), $moduleCode, $record->id));
        }
    }

    /**
     * 詳細の画面に、Records の欄の後ろの帯の欄として出て(登録 = 起動のタグ)、欄を開くと中身が返る。
     */
    public function test_the_record_detail_shows_the_section_in_the_band_and_its_content_in_the_panel(): void
    {
        $project = $this->recordIn('projects');
        $case = $this->appliedCase($this->plan, $this->formApplyingTo('m_plan', $project), $this->applicant);
        $this->actingAs($this->applicant);
        $url = "/records/data/projects/{$project->id}";
        $descriptor = [
            'code' => 'workflow-application-targets', 'heading' => 'このレコードを申請対象にする申請', 'shortHeading' => '申請',
        ];

        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('sections', [[...$descriptor, 'count' => 1]]));
        $this->get("{$url}?panel=workflow-application-targets")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('panelContent.code', 'workflow-application-targets')
            ->where('panelContent.content.rows.0.url', route('workflow.cases.show', $case->id)));
    }

    /**
     * 件数と中身の問い合わせの数は行の数に依らない(参照元を項目で引く読み取りはレコードで 1 回、行は一覧の行と同じまとめた読み取り)。
     */
    public function test_the_number_of_queries_does_not_grow_with_the_rows(): void
    {
        $project = $this->recordIn('projects');
        $this->appliedCase($this->plan, $this->formApplyingTo('m_plan', $project), $this->applicant);
        $one = $this->queriesOfBoth($project);
        $this->appliedCase($this->plan, $this->formApplyingTo('m_plan', $project), $this->applicant, true);
        $this->appliedCase($this->plan2, $this->formApplyingTo('m_plan2', $project), $this->applicant);
        $three = $this->queriesOfBoth($project);

        $this->assertSame($one, $three);
    }

    /**
     * 未完了で札の語に当たらない案件(現在のノードが無いなど)は、画面の案件の状態の札と同じく承認待ちの札になる。
     */
    public function test_an_active_case_without_a_current_node_has_the_waiting_badge_like_the_screen(): void
    {
        $project = $this->recordIn('projects');
        $form = $this->formApplyingTo('m_plan', $project);
        $case = $this->appliedCase($this->plan, $form, $this->applicant);
        $this->nodesOf($case)[1]->update(['state' => WorkflowNodeState::Unreached]);

        $section = app(WorkflowApplicationTargetSection::class)
            ->section($this->contextFor($this->applicant->id), 'projects', $project->id);

        $badge = $section['rows'][0]['cells'][5] ?? [];
        $this->assertSame(['承認待ち', 'pending'], [$badge['text'] ?? null, $badge['tone'] ?? null]);
    }

    private function formApplyingTo(string $moduleCode, Record $applicationTarget): Record
    {
        $form = $this->recordIn($moduleCode);
        $this->refer($form, 'target', $applicationTarget);

        return $form;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function presence(string $moduleCode, Record $record): ?array
    {
        return app(WorkflowApplicationTargetSection::class)
            ->presence($this->contextFor($this->applicant->id), $moduleCode, $record->id);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function section(string $moduleCode, Record $record): ?array
    {
        return app(WorkflowApplicationTargetSection::class)
            ->section($this->contextFor($this->applicant->id), $moduleCode, $record->id);
    }

    /**
     * @param  array{string, string}  $state
     * @return array<string, mixed>
     */
    private function rowOf(WorkflowCase $case, string $flowName, string $appliedOn, array $state): array
    {
        $case->refresh();
        $people = app(WorkflowPeople::class)->users([$case->user_id], CarbonImmutable::parse('2026-10-11'), 'ja');
        $person = $people[$case->user_id];

        return [
            'code' => (string) $case->id,
            'url' => route('workflow.cases.show', $case->id),
            'cells' => [
                ['text' => (string) $case->name, 'tone' => null],
                ['text' => CaseNumbers::format((int) $case->case_number), 'tone' => null],
                ['text' => $flowName, 'tone' => null],
                ['text' => $person['name'] ?? $person['code'], 'tone' => null],
                ['text' => $appliedOn, 'tone' => null],
                ['text' => $state[0], 'tone' => $state[1]],
            ],
        ];
    }

    /**
     * 件数と中身の、それぞれの問い合わせの数。
     *
     * @return array{int, int}
     */
    private function queriesOfBoth(Record $record): array
    {
        return [
            $this->queriesOf(fn () => $this->presence('projects', $record)),
            $this->queriesOf(fn () => $this->section('projects', $record)),
        ];
    }

    private function queriesOf(callable $read): int
    {
        $this->resetRequestScope();
        $this->app['db']->enableQueryLog();
        $this->app['db']->flushQueryLog();
        $read();
        $count = count($this->app['db']->getQueryLog());
        $this->app['db']->disableQueryLog();

        return $count;
    }
}
