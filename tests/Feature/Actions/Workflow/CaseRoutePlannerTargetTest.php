<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Workflow\RouteEdgeInput;
use App\Actions\Workflow\RouteNodeInput;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowRelativeBase;
use App\Enums\Workflow\WorkflowRelativeScope;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Master\UserTerm;
use App\Models\Workflow\WorkflowCaseHistory;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\RunsRoutePlans;
use Tests\Support\TransactionalTestCase;

/**
 * 計画の中の処理対象者(docs の 設計/ワークフロー/ルートの深さ/案件の操作/処理対象者.md 4〜6 節、同時実行と検査.md 14 節の「処理対象者」「指定」「申請者承認防止」の
 * 計画で確かめられる分): 前処理者 = 計画の入力(分岐開始を通っても同じ)、申請者の起点 = 担当組織(申請基準日で決まる)、0 人・申請者だけ・指定の人の無効は 422
 * で何も書かない。期待は共通マスタの初期データから書く。
 */
final class CaseRoutePlannerTargetTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use RunsRoutePlans;

    private int $versionMonth = 0;

    public function test_the_previous_actor_of_the_operation_is_the_origin_even_through_a_branch_start(): void
    {
        $versionId = $this->addVersion($this->previousRoute(), $this->nextStart());

        $byChief = $this->caseOf($versionId, values: ['budget' => 500]);
        $this->step($byChief, 'apply', WorkflowAction::Apply, 'u0001');
        $this->step($byChief, 'chief', WorkflowAction::Approve, 'u0002');
        $this->assertSame('waiting[u0003,u0013]', $this->shape($byChief)['p'], '前処理者 u0002(東京営業課)の上位組織 = 第一営業部');

        $byOther = $this->caseOf($versionId, values: ['budget' => 500]);
        $this->step($byOther, 'apply', WorkflowAction::Apply, 'u0001');
        $this->step($byOther, 'chief', WorkflowAction::Approve, 'u0002', previousCode: 'u0010');
        $this->assertSame('waiting[u0007]', $this->shape($byOther)['p'], '前処理者 u0010(経理部)の上位組織 = 管理本部');
        $this->assertRouteInvariants($byOther);
    }

    public function test_the_applicant_origin_is_the_department_on_the_base_date(): void
    {
        $versionId = $this->addVersion($this->applicantRoute(WorkflowRelativeScope::Own, 'manager'), $this->nextStart());
        $u0013 = $this->seededUser('u0013');

        $before = $this->caseOf($versionId, $u0013, baseDate: '2025-03-31');
        $this->step($before, 'apply', WorkflowAction::Apply, 'u0013');
        $after = $this->caseOf($versionId, $u0013, baseDate: '2025-04-01');
        $this->step($after, 'apply', WorkflowAction::Apply, 'u0013');

        $this->assertSame('waiting[u0011]', $this->shape($before)['boss'], '異動の前 = 開発部の部長');
        $this->assertSame('waiting[u0003]', $this->shape($after)['boss'], '異動の後 = 第一営業部の部長');
    }

    public function test_zero_targets_are_rejected_and_nothing_is_written(): void
    {
        $versionId = $this->addVersion($this->applicantRoute(WorkflowRelativeScope::Parent, null), $this->nextStart());
        $withoutDepartment = $this->caseOf($versionId);
        $withoutDepartment->update(['department_id' => null]);
        $root = $this->caseOf($versionId, $this->seededUser('u0005'));

        foreach (['担当組織の無い申請者' => [$withoutDepartment, 'u0001'], '根の組織の上位組織' => [$root, 'u0005']] as $label => [$case, $applicant]) {
            $before = $this->shape($case);
            $this->assertSame(['case' => 'bossの処理担当者がいないため、この操作はできません'], $this->validationErrors(fn () => $this->step($case, 'apply', WorkflowAction::Apply, $applicant)), $label);
            $this->assertSame($before, $this->shape($case), $label);
            $this->assertSame(0, WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->count(), $label);
        }
    }

    public function test_a_user_retired_on_the_base_date_is_not_a_target(): void
    {
        $route = [[$this->applyNode(), new RouteNodeInput('boss', 'boss', WorkflowNodeKind::Approve, WorkflowTargetKind::Fixed, [new SelectionCondition(SelectionConditionKind::Department, $this->departmentIdOf('accounting'))], ['ja' => '経理部'])], $this->chain(['apply', 'boss'])];
        $versionId = $this->addVersion($route, $this->nextStart());

        $before = $this->caseOf($versionId, baseDate: '2025-09-30');
        $this->step($before, 'apply', WorkflowAction::Apply, 'u0001');
        $retired = $this->caseOf($versionId, baseDate: '2025-10-01');
        $this->step($retired, 'apply', WorkflowAction::Apply, 'u0001');

        $this->assertSame('waiting[u0010,u0014]', $this->shape($before)['boss']);
        $this->assertSame('waiting[u0010]', $this->shape($retired)['boss']);
    }

    public function test_applicant_approval_prevention_rejects_a_node_whose_only_target_is_the_applicant(): void
    {
        $r1 = $this->addVersion($this->r1(), $this->nextStart());
        $chiefApplies = $this->caseOf($r1, $this->seededUser('u0002'), ['budget' => 100]);

        $this->assertSame(
            ['case' => '課長の処理担当者が申請者本人だけのため、この操作はできません。このステップには、申請者承認防止が設定されています。'],
            $this->validationErrors(fn () => $this->step($chiefApplies, 'apply', WorkflowAction::Apply, 'u0002')),
        );

        $withOthers = [[$this->applyNode(), new RouteNodeInput('boss', 'boss', WorkflowNodeKind::Approve, WorkflowTargetKind::Fixed, [new SelectionCondition(SelectionConditionKind::Department, $this->departmentIdOf('tokyo-sales'))], ['ja' => '東京営業課'], preventsApplicantApproval: true)], $this->chain(['apply', 'boss'])];
        $case = $this->caseOf($this->addVersion($withOthers, $this->nextStart()));
        $this->step($case, 'apply', WorkflowAction::Apply, 'u0001');
        $this->assertSame('waiting[u0001,u0002]', $this->shape($case)['boss'], '申請者とほかの人がいれば申請者も処理対象者に入る');
    }

    public function test_an_assigned_applicant_on_a_preventing_council_is_rejected(): void
    {
        $case = $this->caseOf($this->addVersion($this->r1(), $this->nextStart()), values: ['budget' => 100]);
        $this->step($case, 'apply', WorkflowAction::Apply, 'u0001');

        $this->assertSame(
            ['case' => '関係部署の合議 承認 1の処理担当者が申請者本人だけのため、この操作はできません。このステップには、申請者承認防止が設定されています。'],
            $this->validationErrors(fn () => $this->step($case, 'section_chief', WorkflowAction::Approve, 'u0002', ['council' => ['u0001', 'u0010'], 'owner' => ['u0007']])),
        );
        $this->assertSame('waiting[u0002]', $this->shape($case)['section_chief']);
    }

    public function test_an_assigned_user_not_effective_on_the_base_date_rejects_the_arrival(): void
    {
        $versionId = $this->addVersion($this->r3(0), $this->nextStart());

        $case = $this->caseOf($versionId);
        $this->step($case, 'apply', WorkflowAction::Apply, 'u0001');
        $this->assertSame(['case' => '順番の承認の処理担当者がいないため、この操作はできません'], $this->validationErrors(fn () => $this->step($case, 'chief', WorkflowAction::Approve, 'u0002', ['line' => ['u0003', 'u0015']])), '親の展開は子の全員を確かめる');
        $this->assertSame(['case' => '順番の承認の処理担当者がいないため、この操作はできません'], $this->validationErrors(fn () => $this->step($case, 'chief', WorkflowAction::Approve, 'u0002', ['line' => ['u0015']])), '最小 0 で全員が無効でも拒む');

        $this->step($case, 'chief', WorkflowAction::Approve, 'u0002', ['line' => ['u0003', 'u0004']]);
        UserTerm::query()->where('user_id', $this->seededUser('u0004')->id)->update(['end_date' => '2026-10-01']);
        $this->assertSame(['case' => '順番の承認 承認 2の処理担当者がいないため、この操作はできません'], $this->validationErrors(fn () => $this->step($case, 'line.1', WorkflowAction::Approve, 'u0003')), '横配置の子 k + 1 の到達でも確かめる');
        $this->assertSame('waiting[u0003]', $this->shape($case)['line.1']);
    }

    /**
     * 申請 → chief(u0002)→ 分岐(予算 300 以上 = p(前処理者・上位組織・役職なし)/ 未満 = q(u0004))→ e。
     *
     * @return array{list<RouteNodeInput>, list<RouteEdgeInput>}
     */
    private function previousRoute(): array
    {
        return [
            [$this->applyNode(), $this->approveNode('chief', 'u0002'), new RouteNodeInput('br', '分岐', WorkflowNodeKind::BranchStart, closingCode: 'e'),
                new RouteNodeInput('p', 'p', WorkflowNodeKind::Approve, WorkflowTargetKind::Relative, relativeBase: WorkflowRelativeBase::Previous, relativeScope: WorkflowRelativeScope::Parent),
                $this->approveNode('q', 'u0004'), new RouteNodeInput('e', '分岐終了', WorkflowNodeKind::BranchEnd)],
            [new RouteEdgeInput('apply', 'chief'), new RouteEdgeInput('chief', 'br'), new RouteEdgeInput('br', 'p', 1, '大', ['all' => [['field' => 'budget', 'op' => 'ge', 'value' => 300]]]),
                new RouteEdgeInput('br', 'q', 2, '小', ['all' => [['field' => 'budget', 'op' => 'lt', 'value' => 300]]]), new RouteEdgeInput('p', 'e'), new RouteEdgeInput('q', 'e')],
        ];
    }

    /**
     * 申請 → boss(申請者・範囲・役職)。
     *
     * @return array{list<RouteNodeInput>, list<RouteEdgeInput>}
     */
    private function applicantRoute(WorkflowRelativeScope $scope, ?string $post): array
    {
        return [
            [$this->applyNode(), new RouteNodeInput('boss', 'boss', WorkflowNodeKind::Approve, WorkflowTargetKind::Relative, relativeBase: WorkflowRelativeBase::Applicant, relativeScope: $scope, companyPostId: $post === null ? null : $this->postId($post))],
            $this->chain(['apply', 'boss']),
        ];
    }

    private function nextStart(): string
    {
        return sprintf('2026-%02d-01', ++$this->versionMonth);
    }
}
