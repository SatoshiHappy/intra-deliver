<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Enums\Records\RecordModuleAction;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Workflow\CaseDeadlines;
use App\Queries\Workflow\WorkflowPeople;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Database\Seeders\WorkflowApplicationModules;
use Database\Seeders\WorkflowSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * ワークフローのページの props と見える範囲(docs の 設計/ワークフロー/画面/README.md 1.1〜2 節・画面/申請と案件.md 3〜5 節、認可と一覧.md 7 節の HTTP の Feature テスト):
 * フローの一覧・申請(新規・一時保存・
 * 再申請)・案件一覧・案件の詳細の props の形、u0001 が初期データの案件のうち自分の分だけを見て、ほかの案件の詳細は 404、操作の画面を開けないときの 404・403・
 * 詳細への移動(一時保存の画面 = 見えない 404 → 申請できる人でない 403 → 一時保存でなければ詳細へ、再申請の画面 = 見えない・一時保存 404 → update 403 →
 * 再申請ができなければ詳細へ)。初期データを入れ、ルートの認可を外さずに開く。
 */
final class WorkflowPagesTest extends TransactionalTestCase
{
    use UsesSeededCases;
    use UsesSeededRecords;
    use WritesWorkflowInputs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_flow_list_is_empty_for_a_guest_and_has_the_two_flows_for_u0001(): void
    {
        $this->get('/workflow/flows')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Workflow/FlowIndex')
            ->where('flows', [])
            ->where('account.user', null)
        );

        $this->actingAsUser('u0001');
        $this->get('/workflow/flows')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('flows', [
                ['code' => 'project_plan', 'name' => 'プロジェクト計画の承認', 'description' => 'プロジェクトの担当・期間・予算とマイルストーンの計画を、上長と部長の承認で決めます。', 'moduleName' => 'プロジェクト計画の申請'],
                ['code' => 'issue_resolution', 'name' => '課題の対応の承認', 'description' => 'プロジェクトで起きた課題の対応方針を、上長と部長の承認で決めます。', 'moduleName' => '課題の対応の申請'],
            ])
        );
    }

    public function test_the_new_application_form_has_the_props_of_the_design(): void
    {
        $this->actingAsUser('u0001');

        $this->get('/workflow/flows/project_plan/apply')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Workflow/Apply')
            ->where('mode', 'new')
            ->where('flow.code', 'project_plan')
            ->where('flow.name', 'プロジェクト計画の承認')
            ->where('case', null)
            ->where('module.code', 'project_plan_application')
            ->where('initial', null)
            ->where('titleField', 'name')
            ->where('baseDate', CarbonImmutable::today(AccessContext::TIMEZONE)->toDateString())
            ->where('next.version', 3)
            ->where('next.departmentName', '東京営業課')
            ->where('next.nodes.0.name', '課長')
            ->where('next.nodes.0.targets.0.code', 'u0002')
            ->has('next.nodes', 1)
            ->has('next.nodes.0.targets', 1)
            ->where('next.noVersionMessage', null)
            ->where('next.dependsOnContent', false)
            ->where('commentMaxLength', 4000)
        );
    }

    /**
     * 申請基準日の候補(クエリ・一時保存の値)は、日付として読めれば今日の前後 1 年の外でもそのまま baseDate と next に使う(範囲は送った後の 422 で示す)。
     * 読めない候補は飛ばし、どれも読めなければ今日(設計/ワークフロー/ルートの深さ/画面/案件の側の画面.md 5 節)。範囲の外の日 = 今日の 1 年後の翌日(計画のフローの版 3 は終わりなし)。
     */
    public function test_a_readable_base_date_outside_a_year_around_today_is_kept_in_the_form_and_the_next_steps(): void
    {
        $this->actingAsUser('u0001');
        $today = CarbonImmutable::today(AccessContext::TIMEZONE);
        $outside = $today->addYearNoOverflow()->addDay()->toDateString();
        $saved = $this->post('/workflow/flows/project_plan/drafts', ['name' => '範囲の外の下書き', 'comment' => null, 'values' => ['name' => '範囲の外の下書き'], 'base_date' => $outside], ['X-Inertia' => 'true']);
        $draft = $this->caseIdIn($saved->assertSessionHasNoErrors(), '#/workflow/drafts/(\d+)/edit$#D');
        $formOf = fn (string $url) => $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Workflow/Apply'))->viewData('page')['props'];
        $summaryOf = fn (array $props) => [$props['baseDate'], $props['next']['version']];

        $this->assertSame([
            [$outside, 3],
            [$today->toDateString(), 3],
            [$outside, 3],
            [$outside, 3],
        ], [
            $summaryOf($formOf("/workflow/flows/project_plan/apply?base_date={$outside}")),
            $summaryOf($formOf('/workflow/flows/project_plan/apply?base_date=2026-02-30')),
            $summaryOf($formOf("/workflow/drafts/{$draft}/edit")),
            $summaryOf($formOf("/workflow/drafts/{$draft}/edit?base_date=x")),
        ]);
    }

    public function test_the_application_form_is_404_for_an_unknown_flow_and_403_for_a_guest(): void
    {
        $this->get('/workflow/flows/no_such_flow/apply')->assertNotFound();
        $this->get('/workflow/flows/project_plan/apply')->assertForbidden();
    }

    public function test_the_draft_form_opens_the_own_draft_and_is_404_for_others(): void
    {
        $draft = $this->seededDraft();

        $this->get("/workflow/drafts/{$draft}/edit")->assertNotFound();
        $this->actingAsUser('u0002');
        $this->get("/workflow/drafts/{$draft}/edit")->assertNotFound();

        $this->actingAsUser('u0001');
        $this->get('/workflow/drafts/'.$this->caseNumbered(6).'/edit')->assertNotFound();
        $this->get('/workflow/drafts/999999999/edit')->assertNotFound();
        $this->get("/workflow/drafts/{$draft}/edit")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Workflow/Apply')
            ->where('mode', 'draft')
            ->where('flow.code', 'issue_resolution')
            ->where('case.id', $draft)
            ->where('case.number', null)
            ->where('initial.name', '課題の対応の承認 請求書の様式の変更')
            ->where('initial.values.title', '請求書の様式の変更')
            ->where('initial.values.priority.code', 'medium')
        );
    }

    public function test_the_draft_form_is_403_for_who_cannot_apply_before_the_state_is_judged(): void
    {
        $this->actingAsUser('u0001');
        $this->deny(WorkflowApplicationModules::ISSUE, RecordModuleAction::Create);
        $this->deny(WorkflowApplicationModules::PLAN, RecordModuleAction::Create);

        $this->get('/workflow/drafts/'.$this->seededDraft().'/edit')->assertForbidden();
        $this->get('/workflow/drafts/'.$this->caseNumbered(4).'/edit')->assertForbidden();
    }

    public function test_the_draft_form_moves_to_the_detail_with_a_toast_when_the_case_is_not_a_draft(): void
    {
        $this->actingAsUser('u0001');
        $case = $this->caseNumbered(4);

        $this->get("/workflow/drafts/{$case}/edit")
            ->assertRedirect("/workflow/cases/{$case}")
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'この申請は今この操作をできません']);
        $this->get("/workflow/cases/{$case}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Workflow/CaseShow')
            ->hasFlash('toast', ['type' => 'error', 'message' => 'この申請は今この操作をできません'])
        );
    }

    public function test_the_reapplication_form_opens_for_the_applicant_waiting_for_reapplication(): void
    {
        $this->actingAsUser('u0001');
        $case = $this->caseNumbered(4);

        $this->get("/workflow/cases/{$case}/reapply")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Workflow/Apply')
            ->where('mode', 'reapply')
            ->where('case.id', $case)
            ->where('case.number', 'WF-000004')
            ->where('initial.name', 'プロジェクト計画の承認 販促資料の刷新')
            ->where('initial.comment', null)
            ->where('initial.values.name', '販促資料の刷新')
            ->where('initial.values.budget', 80)
            ->where('baseDate', WorkflowSeeder::DATES['stage2_plan_base_date'])
            ->where('next.version', 2)
            ->where('next.nodes.0.name', '課長の承認')
        );
    }

    public function test_the_reapplication_form_moves_to_the_detail_with_a_toast_when_the_case_cannot_be_reapplied(): void
    {
        $this->actingAsUser('u0001');
        $case = $this->caseNumbered(2);

        $this->get("/workflow/cases/{$case}/reapply")
            ->assertRedirect("/workflow/cases/{$case}")
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'この申請は今この操作をできません']);
    }

    public function test_the_reapplication_form_is_404_for_a_draft_and_403_without_update(): void
    {
        $this->actingAsUser('u0001');
        $this->get('/workflow/cases/'.$this->seededDraft().'/reapply')->assertNotFound();

        $this->deny(WorkflowApplicationModules::PLAN, RecordModuleAction::Update);
        $this->get('/workflow/cases/'.$this->caseNumbered(4).'/reapply')->assertForbidden();
    }

    public function test_the_case_list_has_the_tabs_counts_and_rows_of_u0001(): void
    {
        $this->actingAsUser('u0001');

        $this->get('/workflow/cases')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Workflow/CaseIndex')
            ->where('query', ['tab' => 'pending', 'scope' => 'active', 'q' => ''])
            ->where('counts', ['draft' => 1, 'pending' => 1, 'unconfirmed' => 0])
            ->where('cases.total', 1)
            ->where('commentMaxLength', 4000)
            ->where('cases.data.0.number', 'WF-000004')
            ->where('cases.data.0.currentSummary', ['state' => 'reapply', 'name' => '申請'])
            ->where('cases.data.0.can', ['pullBack' => false, 'reapply' => true, 'withdraw' => true, 'editDraft' => false, 'deleteDraft' => false, 'bulkApprove' => false, 'confirm' => false])
        );
        $this->get('/workflow/cases?tab=pending&q=wf-000004')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('query', ['tab' => 'pending', 'scope' => 'active', 'q' => 'wf-000004'])
            ->where('cases.data.0.number', 'WF-000004')
        );
        $this->get('/workflow/cases?tab=pending&q=%FF')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('query.q', '')->where('cases.total', 1));
        $this->get('/workflow/cases?tab=draft&scope=completed&page=x')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('query', ['tab' => 'draft', 'scope' => 'active', 'q' => ''])
            ->where('cases.data.0.number', null)
            ->where('cases.data.0.state', 'draft')
            ->where('cases.data.0.can.editDraft', true)
            ->where('cases.data.0.can.deleteDraft', true)
        );
        $this->get('/workflow/cases?tab=unknown')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('query', ['tab' => 'pending', 'scope' => 'active', 'q' => ''])
            ->reloadOnly(['query', 'counts', 'cases'], fn (Assert $reload) => $reload->has('query')->has('counts')->has('cases'))
        );
    }

    public function test_a_guest_sees_empty_tabs_with_zero_counts(): void
    {
        $this->get('/workflow/cases')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('counts', ['draft' => 0, 'pending' => 0, 'unconfirmed' => 0])
            ->where('cases.total', 0)
        );
    }

    public function test_u0001_sees_the_details_of_the_own_cases_only(): void
    {
        $this->actingAsUser('u0001');

        foreach ([1, 2, 3, 4, 5, 8] as $number) {
            $this->get('/workflow/cases/'.$this->caseNumbered($number))->assertOk();
        }

        foreach ([6, 7] as $number) {
            $this->get('/workflow/cases/'.$this->caseNumbered($number))->assertNotFound();
        }

        $this->get('/workflow/cases/'.$this->seededDraft())->assertNotFound();
        $this->get('/workflow/cases/999999999')->assertNotFound();
    }

    public function test_the_case_detail_has_the_props_of_the_design(): void
    {
        $this->actingAsUser('u0002');
        $case = $this->caseNumbered(5);
        $u0002 = $this->userIdOf('u0002');
        $name = app(WorkflowPeople::class)->users([$u0002], CarbonImmutable::today(), 'ja')[$u0002]['name'];
        $operations = ['approve', 'approveEarly', 'reject', 'sendBack', 'hold', 'transfer'];
        $principals = array_fill_keys($operations, [['id' => $u0002, 'name' => (string) $name, 'isSelf' => true]]);

        $this->get("/workflow/cases/{$case}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Workflow/CaseShow')
            ->where('case.number', 'WF-000005')
            ->where('case.state', 'active')
            ->where('case.currentSummary', ['state' => 'waiting', 'name' => '課長の承認'])
            ->where('case.baseDate', WorkflowSeeder::DATES['stage2_plan_base_date'])
            ->where('case.version', 2)
            ->where('content.module.code', 'project_plan_application')
            ->where('content.record.values.budget', 350)
            ->where('content.updatedAfterSubmission', false)
            ->has('nodes', 3)
            ->where('nodes.1.people.0.isMe', true)
            ->where('nodes.0.processed.action', 'reapply')
            ->has('histories', 3)
            ->where('histories.2.action', 'reapply')
            ->has('histories.2.diff.fields', 2)
            ->where('can', [
                'pullBack' => false, 'reapply' => false, 'withdraw' => false, 'confirm' => false, 'operate' => false,
            ])
            ->where('operation', null)
            ->where('commentMaxLength', 4000)
            ->where('nodeActions', [[
                'nodeId' => $this->nodeIdOf($case, 2), 'name' => '課長の承認', 'approve' => true, 'approveEarly' => true, 'reject' => true, 'sendBack' => true,
                'hold' => true, 'release' => false, 'transfer' => true,
                'blockedReason' => null, 'holdBlockedReason' => null, 'sendBackTargets' => [['id' => $this->nodeIdOf($case, 1), 'name' => '申請']], 'sendBackRestricted' => false, 'isCouncilVote' => false,
                'principals' => $principals,
                'designations' => [],
            ]])
            ->where('pullBackNodes', [])
            ->where('casePrincipal', $this->userIdOf('u0001'))
            ->where('proxyNote', null)
            ->has('confirmations', 2)->where('confirmations.0.people.0.status', 'waiting')
            ->where('case.deadline.dueOn', $this->dueOnOf($case))
            ->has('related')
        );
    }

    /** 現在のステップの期限日(到達した日 + 写した期限の日数 = 案件の運用/案件の操作/README.md の 1.4 節) */
    private function dueOnOf(int $caseId): ?string
    {
        $node = WorkflowCaseNode::query()->where('workflow_case_id', $caseId)->where('state', 'waiting')->sole();
        $reachedAt = $node->reached_at ?? throw new LogicException("現在のノード {$node->id} に到達日時が無い");

        return app(CaseDeadlines::class)->dueOn($reachedAt, $node->deadline_days);
    }

    private function nodeIdOf(int $caseId, int $position): int
    {
        return WorkflowCaseNode::query()->where('workflow_case_id', $caseId)->where('position', $position)->sole()->id;
    }
}
