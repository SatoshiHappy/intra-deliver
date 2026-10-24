<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Workflow\ApplyCase;
use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\ApproveCaseEarly;
use App\Actions\Workflow\PullBackCase;
use App\Actions\Workflow\RejectCase;
use App\Actions\Workflow\RouteNodeInput;
use App\Actions\Workflow\SendBackCase;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowCase;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\RunsRoutePlans;
use Tests\Support\TransactionalTestCase;

/**
 * 申請者承認防止(docs の 設計/ワークフロー/ルートの深さ/案件の操作/処理対象者.md 4.6・6 節、同時実行と検査.md 13 節・14 節の「申請者承認防止」)。
 * 防止オンのノードに申請者とほかの人が展開されると、申請者は承認・承認終了・否認が 422 case(何も変わらない)、差戻しはでき、ほかの人は承認できる(モック 6a)。
 * 展開の結果が申請者だけなら、そのノードへの到達を起こす操作(申請・前のノードの承認)が 422 case で何も書かない。防止オフのノードでは申請者が承認できる。
 */
final class ApplicantApprovalPreventionTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use RunsRoutePlans;

    private const BLOCKED = '申請者本人は、この申請を承認できません。このステップには、申請者承認防止が設定されています。';

    public function test_the_applicant_among_the_targets_cannot_approve_approve_early_or_reject(): void
    {
        $this->addSalesRoute(prevents: true);
        $case = $this->apply();
        $node = $this->labelIds($case)['sales'];
        $before = [$this->shape($case), $this->workflowCounts(), $this->revisionOf($case)];

        $this->assertSame('waiting[u0001,u0002]', $this->shape($case)['sales']);

        foreach ([ApproveCase::class, ApproveCaseEarly::class, RejectCase::class] as $operation) {
            $errors = $this->validationErrors(fn () => app($operation)->execute($this->contextOf($this->seededUser('u0001')), $case->id, $this->revisionOf($case), $node, null));
            $this->assertSame(['case' => self::BLOCKED], $errors, $operation);
        }

        $this->assertSame($before, [$this->shape($case), $this->workflowCounts(), $this->revisionOf($case)]);
    }

    public function test_the_applicant_can_send_back_and_another_target_can_approve(): void
    {
        $this->addSalesRoute(prevents: true);
        $sentBack = $this->apply();
        $approved = $this->apply();

        app(SendBackCase::class)->execute($this->contextOf($this->seededUser('u0001')), $sentBack->id, $this->revisionOf($sentBack), $this->labelIds($sentBack)['sales'], $this->labelIds($sentBack)['apply'], null);
        app(ApproveCase::class)->execute($this->contextOf($this->seededUser('u0002')), $approved->id, $this->revisionOf($approved), $this->labelIds($approved)['sales'], null);

        $this->assertSame(['apply' => 'reapply[u0001]', 'sales' => 'unreached', 'final' => 'unreached'], $this->shape($sentBack));
        $this->assertSame(['apply' => 'done', 'sales' => 'done', 'final' => 'waiting[u0010]'], $this->shape($approved));
        $this->assertRouteInvariants($sentBack);
        $this->assertRouteInvariants($approved);
    }

    /**
     * 申請者が防止のノードで差戻した後、その差戻しの引戻しは 422 case で何も書かない(引き戻すノードの処理対象者が申請者だけになる = 4.6・操作.md 10.5)。
     */
    public function test_the_applicant_cannot_pull_back_a_send_back_from_a_preventing_node(): void
    {
        $this->addSalesRoute(prevents: true);
        $case = $this->apply();
        $node = $this->labelIds($case)['sales'];
        app(SendBackCase::class)->execute($this->contextOf($this->seededUser('u0001')), $case->id, $this->revisionOf($case), $node, $this->labelIds($case)['apply'], null);
        $before = [$this->shape($case), $this->workflowCounts(), $this->revisionOf($case)];

        $errors = $this->validationErrors(fn () => app(PullBackCase::class)->execute($this->contextOf($this->seededUser('u0001')), $case->id, $this->revisionOf($case), $node, null));

        $this->assertSame(['case' => '東京営業課の処理担当者が申請者本人だけのため、この操作はできません。このステップには、申請者承認防止が設定されています。'], $errors);
        $this->assertSame($before, [$this->shape($case), $this->workflowCounts(), $this->revisionOf($case)]);
    }

    public function test_without_the_flag_the_applicant_can_approve(): void
    {
        $this->addSalesRoute(prevents: false);
        $case = $this->apply();

        app(ApproveCase::class)->execute($this->contextOf($this->seededUser('u0001')), $case->id, $this->revisionOf($case), $this->labelIds($case)['sales'], null);

        $this->assertSame(['apply' => 'done', 'sales' => 'done', 'final' => 'waiting[u0010]'], $this->shape($case));
    }

    /**
     * 展開の結果が申請者だけのノードへ到達する操作は 422 case: 申請(承認 1 が申請者だけ)と、前のノードの承認(次が申請者だけ)。どちらも何も書かない。
     */
    public function test_reaching_a_node_whose_only_target_is_the_applicant_is_rejected(): void
    {
        $message = ['case' => '申請者だけの処理担当者が申請者本人だけのため、この操作はできません。このステップには、申請者承認防止が設定されています。'];
        $this->addVersion([[$this->applyNode(), $this->approveNode('only', 'u0001', ['apply'], prevents: true)], $this->chain(['apply', 'only'])]);
        $counts = $this->workflowCounts();

        $applied = $this->validationErrors(fn () => app(ApplyCase::class)->execute($this->contextOf($this->seededUser('u0001')), self::FLOW_CODE, $this->projectInput(), $this->operationDay()->toDateString()));

        $this->assertSame(['case' => 'onlyの処理担当者が申請者本人だけのため、この操作はできません。このステップには、申請者承認防止が設定されています。'], $applied);
        $this->assertSame($counts, $this->workflowCounts());

        $this->addVersion([[$this->applyNode(), $this->approveNode('first', 'u0002', ['apply']), new RouteNodeInput('only', '申請者だけ', WorkflowNodeKind::Approve, WorkflowTargetKind::Fixed, [$this->userCondition('u0001')], ['ja' => '申請者だけ'], preventsApplicantApproval: true, sendBackCodes: ['apply'])], $this->chain(['apply', 'first'], ['first', 'only'])], '2026-06-01');
        $case = $this->apply();
        $before = [$this->shape($case), $this->workflowCounts(), $this->revisionOf($case)];

        $errors = $this->validationErrors(fn () => app(ApproveCase::class)->execute($this->contextOf($this->seededUser('u0002')), $case->id, $this->revisionOf($case), $this->labelIds($case)['first'], null));

        $this->assertSame($message, $errors);
        $this->assertSame($before, [$this->shape($case), $this->workflowCounts(), $this->revisionOf($case)]);
    }

    /**
     * 東京営業課の所属(申請者 u0001 と課長 u0002)を処理対象者にする承認のノード。
     */
    private function salesNode(bool $prevents): RouteNodeInput
    {
        return new RouteNodeInput('sales', '東京営業課', WorkflowNodeKind::Approve, WorkflowTargetKind::Fixed, [new SelectionCondition(SelectionConditionKind::Department, $this->departmentIdOf('tokyo-sales'))], ['ja' => '東京営業課'], preventsApplicantApproval: $prevents, sendBackCodes: ['apply']);
    }

    /**
     * 申請 → 東京営業課 → final(u0010)の版を足す。
     */
    private function addSalesRoute(bool $prevents): void
    {
        $this->addVersion([[$this->applyNode(), $this->salesNode($prevents), $this->approveNode('final', 'u0010', ['apply', 'sales'])], $this->chain(['apply', 'sales'], ['sales', 'final'])]);
    }

    private function apply(): WorkflowCase
    {
        $result = app(ApplyCase::class)->execute($this->contextOf($this->seededUser('u0001')), self::FLOW_CODE, $this->projectInput(), $this->operationDay()->toDateString());
        $case = WorkflowCase::query()->findOrFail($result->caseId);
        $this->assertRouteInvariants($case);

        return $case;
    }
}
