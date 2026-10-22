<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Workflow\CreateDraft;
use App\Actions\Workflow\DraftInput;
use App\Actions\Workflow\RejectCase;
use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowFlowAction;
use App\Models\Authz\AuthzPolicy;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\CaseVisibility;
use Tests\Support\GrantsFlowPermissions;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 管理の述語(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 1 節・6 節): 一時保存でなく、案件のフローの workflow-flow の read を許される。
 * 初期データのポリシーではシステム管理者(u0004)だけが全フローの read を持つ。業務モジュールの read には依らない。
 * 閲覧できる・できないは、閲覧の判定と 2 段目の詳細のルート(GET が 200 か 404)の両方で確かめる。
 * 案件 = テストのフロー wf_plan の未完了の案件・一時保存と、別のフロー wf_other の完了の案件(どれも申請者 u0001)。
 * u0006 はどの案件の処理もせず、フローの参照者(経理部)でもない。
 */
final class CaseVisibilityManagedTest extends TransactionalTestCase
{
    use GrantsFlowPermissions;
    use RunsWorkflowOperations;

    private WorkflowCase $active;

    private WorkflowCase $completed;

    private int $draftId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo($this->operationDay()->setTime(12, 0));
        $this->createFlow();
        $this->createFlow('wf_other', '別のフロー');
        $applicant = $this->seededUser('u0001');
        $this->active = $this->applyAs($applicant);
        $this->completed = $this->applyAs($applicant, 'wf_other');
        $this->operate(RejectCase::class, $this->seededUser('u0002'), $this->completed);
        $draft = new DraftInput('一時保存', null, ['name' => '一時保存'], []);
        $this->draftId = app(CreateDraft::class)->execute($this->contextOf($applicant), self::FLOW_CODE, $draft);
    }

    /**
     * システム管理者には、全フローの一時保存でない案件が見え(初期データの案件を含む)、一時保存は見えない。
     */
    public function test_the_system_administrator_sees_every_applied_case_of_every_flow_but_no_draft(): void
    {
        $applied = WorkflowCase::query()->where('state', '<>', WorkflowCaseState::Draft)
            ->orderBy('id')->pluck('id')->all();

        $this->assertSame($applied, $this->managedIds('u0004'));
        $this->assertContains($this->completed->id, $applied);
        $this->assertDetail($this->active->id, 'u0004', true);
        $this->assertDetail($this->completed->id, 'u0004', true);
        $requester = $this->requester('u0004');
        $this->assertFalse($this->visibility()->isVisible($requester, $this->operationDay(), $this->draftId));
    }

    public function test_a_user_without_read_sees_no_case_through_it(): void
    {
        $this->assertSame([], $this->managedIds('u0006'));
        $this->assertDetail($this->active->id, 'u0006', false);
        $this->assertDetail($this->completed->id, 'u0006', false);
    }

    /**
     * テストの中でポリシーを足すと、read を許されたフローの案件だけが見える。
     */
    public function test_only_the_cases_of_the_flows_granted_read_are_visible(): void
    {
        $this->grantFlow($this->seededUser('u0006'), 'wf_other', WorkflowFlowAction::Read);
        $this->grantFlow($this->seededUser('u0007'), 'wf_plan', WorkflowFlowAction::Operate);

        $this->assertSame([$this->completed->id], $this->managedIds('u0006'));
        $this->assertDetail($this->completed->id, 'u0006', true);
        $this->assertDetail($this->active->id, 'u0006', false);
        $this->assertSame([], $this->managedIds('u0007'));
        $this->assertDetail($this->active->id, 'u0007', false);
    }

    /**
     * 業務モジュールの read のポリシーを全部消しても、フローの read で見える(案件の閲覧と業務データの閲覧は独立)。
     */
    public function test_it_does_not_depend_on_the_read_of_the_record_module(): void
    {
        AuthzPolicy::query()->where(['resource_type' => 'record-module', 'action' => 'read'])->delete();
        $this->grantFlow($this->seededUser('u0006'), 'wf_plan', WorkflowFlowAction::Read);

        $this->assertSame([$this->active->id], $this->managedIds('u0006'));
        $this->assertDetail($this->active->id, 'u0006', true);
    }

    /**
     * @return list<int> 管理の述語に合う案件の id(昇順)
     */
    private function managedIds(string $userCode): array
    {
        $requester = $this->requester($userCode);
        $managed = $this->visibility()->managed(WorkflowCase::query(), $requester, $this->operationDay());

        return array_values(array_map('intval', $managed->orderBy('id')->pluck('id')->all()));
    }

    /**
     * 閲覧の判定と詳細の GET(200 か 404)が、期待の見え方と一致する。
     */
    private function assertDetail(int $caseId, string $userCode, bool $visible): void
    {
        $isVisible = $this->visibility()->isVisible($this->requester($userCode), $this->operationDay(), $caseId);
        $this->assertSame($visible, $isVisible, $userCode);
        $this->actingAs($this->seededUser($userCode))->get(route('workflow.cases.show', ['case' => $caseId]))
            ->assertStatus($visible ? 200 : 404);
    }

    private function requester(string $userCode): Requester
    {
        return new Requester($this->seededUser($userCode)->id, null);
    }

    private function visibility(): CaseVisibility
    {
        return app(CaseVisibility::class);
    }
}
