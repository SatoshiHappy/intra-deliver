<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowOperation;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\ApplicableFlows;
use App\Queries\Workflow\ApplicantApprovalGuard;
use App\Queries\Workflow\BulkApproval;
use App\Queries\Workflow\CaseActor;
use App\Queries\Workflow\CaseEntryGate;
use App\Queries\Workflow\CaseOperationDecider;
use App\Queries\Workflow\CaseOperationTable;
use App\Queries\Workflow\CaseRoles;
use App\Queries\Workflow\CaseSituation;
use App\Queries\Workflow\CaseVisibility;
use App\Queries\Workflow\FlowPermissions;
use App\Queries\Workflow\PrincipalContext;
use App\Support\AccessContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * 案件の操作の判定の順(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 7 節の 2、設計/ワークフロー/案件の運用/案件の操作/操作.md の
 * 7 節)。既にある案件の操作は {@see self::lockAndPermit()} を通る。確認は改訂番号を見ないので {@see self::lockForConfirm()}、一括承認は
 * 今の状態で組を出し直すので {@see self::lockAndPermitBulkApproval()} を通る。呼び手は結果を導き直さない(判定と操作が食い違わないため)。
 * 判定そのものは読み取りの 1 か所を呼び、ここでは二重に書かない。呼び手は 1 つのトランザクションの中で呼ぶ(鍵はトランザクションの終わりまで持つ)。
 */
class CaseOperationGuard
{
    public function __construct(
        private CaseVisibility $visibility,
        private ApplicableFlows $applicableFlows,
        private CaseOperationDecider $decider,
        private CaseOperationTable $table,
        private CaseEntryGate $gate,
        private ApplicantApprovalGuard $applicantGuard,
        private CaseProxyChecks $proxyChecks,
        private FlowPermissions $flowPermissions,
    ) {}

    /**
     * 既にある案件の操作の判定の順 1〜5(鍵の強さ = 設計/ワークフロー/ルートの深さ/データモデル/直列化と検査.md 4.1)。状況は処理者で 1 回だけ読み、
     * 表と業務処理が同じ状況を使う。
     *
     * @param  int  $revision  画面を開いた時の改訂番号
     * @param  (Closure(WorkflowCase): void)|null  $authorize  403 の判定(申請の系統 = {@see self::requireApplicable()}、再申請 = {@see self::requireUpdatable()})
     * @param  int|null  $nodeId  操作するノード(承認・承認終了・否認・差戻し・引戻しの入力 node_id。ほかは null)
     * @param  int|null  $principalUserId  処理の操作の入力 principal_user_id(null = 自分として。一時保存の操作は受けない)
     *
     * @throws ModelNotFoundException<WorkflowCase> 無い・閲覧できないとき
     * @throws AuthorizationException $authorize が投げたとき
     * @throws ValidationException 改訂番号・ノード・表の誤り(case)、処理担当者の誤り(principal_user_id)
     */
    public function lockAndPermit(
        WorkflowOperation $operation,
        AccessContext $context,
        int $caseId,
        int $revision,
        ?Closure $authorize = null,
        ?int $nodeId = null,
        ?int $principalUserId = null,
    ): CaseOperationPermit {
        $case = $this->lockVisible($operation, $context, $caseId);
        $flow = $operation === WorkflowOperation::Apply
            ? WorkflowFlow::query()->whereKey($case->workflow_flow_id)->sharedLock()->firstOrFail()
            : null;

        if ($authorize !== null) {
            $authorize($case);
        }

        if ($case->revision !== $revision) {
            throw CaseOperationErrors::ofCase(CaseOperationErrors::STALE_REVISION);
        }

        $node = $nodeId === null ? null : $this->nodeOf($case, $nodeId);
        $onApplyNode = in_array($operation, [WorkflowOperation::Reapply, WorkflowOperation::Withdraw], true);
        $kind = $onApplyNode ? WorkflowNodeKind::Apply : $node?->kind;
        $processorUserId = $this->proxyChecks->processorOf($operation, $context, $case, $kind, $principalUserId);
        $requester = Requester::fromAccessContext($context);
        $situation = $this->decider->situationsOf($requester, $context->date, [$case], $processorUserId)[$case->id];
        $node = $onApplyNode ? $this->applyNodeOf($situation) : $node;
        $this->requirePermitted($operation, $context, $situation, $node?->id);

        return new CaseOperationPermit($situation, $node, $flow);
    }

    /**
     * 確認の判定(設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.4 節)。確認は案件を変えないので改訂番号を見ない。
     * 状況は自分で読む(確認は代理で行わない)。
     *
     * @throws ModelNotFoundException<WorkflowCase> 無い・閲覧できないとき
     * @throws ValidationException 確認できる確認が無いとき(case)
     */
    public function lockForConfirm(AccessContext $context, int $caseId): CaseSituation
    {
        $case = $this->lockVisible(WorkflowOperation::Confirm, $context, $caseId);
        $requester = Requester::fromAccessContext($context);
        $situation = $this->decider->situationsOf($requester, $context->date, [$case])[$case->id];

        if (! $this->decider->permitsIn(WorkflowOperation::Confirm, $requester, $context->date, $situation, null)) {
            throw CaseOperationErrors::ofCase(CaseOperationErrors::NO_CONFIRMATION);
        }

        return $situation;
    }

    /**
     * 一括承認の 1 件の鍵と閲覧(単独の承認と同じ)と、今の状態で選べる組(設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.7 節。
     * 状況は自分で読む)。改訂番号と組の照合は呼び手が行う。
     *
     * @return array{WorkflowCase, BulkApproval|null} 鍵を取った案件と組(選べなければ null)
     *
     * @throws ModelNotFoundException<WorkflowCase> 無い・閲覧できないとき
     */
    public function lockForBulkApprove(AccessContext $context, int $caseId): array
    {
        [$situation, $approval] = $this->lockBulkApproval($context, $caseId);

        return [$situation->case, $approval];
    }

    /**
     * 一括承認の 1 件の判定(設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.7 節): 組の判定は画面が示した組を今の状態で選べるかを見る。
     * その後に、単独の承認と同じ処理担当者の確かめと表の判定を通す(組の判定と単独の判定が食い違っても、単独で拒まれる組を書き込まない)。
     * 状況は読み直さず、代理元として承認するときは処理者を替えた状況({@see CaseSituation::withProcessor()})を使う。
     *
     * @return CaseOperationPermit|null 判定の結果(改訂番号か組が違う・組が選べないときは null)
     *
     * @throws ModelNotFoundException<WorkflowCase> 無い・閲覧できないとき
     * @throws ValidationException 処理担当者の確かめか表の判定に外れたとき(principal_user_id・case)
     */
    public function lockAndPermitBulkApproval(AccessContext $context, BulkApprovalInput $row): ?CaseOperationPermit
    {
        [$situation, $approval] = $this->lockBulkApproval($context, $row->caseId);

        if ($approval === null || ! $row->isCurrent($situation->case->revision, $approval)) {
            return null;
        }

        [$case, $principal] = [$situation->case, $approval->principalUserId];
        $node = $this->nodeOf($case, $approval->nodeId);
        $processor = $this->proxyChecks->processorOf(
            WorkflowOperation::Approve, $context, $case, $node->kind, $principal,
        );
        $processed = $principal === null ? $situation : $situation->withProcessor($processor);
        $this->requirePermitted(WorkflowOperation::Approve, $context, $processed, $node->id);

        return new CaseOperationPermit($processed, $node, null);
    }

    /**
     * 判定の順の 6(申請者承認防止 = 処理対象者.md 6.1、設計/ワークフロー/案件の運用/案件の操作/README.md の 5 節。
     * 文言 = 設計/ワークフロー/案件の運用/案件の操作/同時実行と検査.md の 13 節)。
     *
     * @param  bool  $holds  保留か(偽 = 承認・承認終了・否認)
     *
     * @throws ValidationException 申請者承認防止に当たるとき(case)
     */
    public function rejectApplicant(CaseOperationPermit $permit, CaseActor $actor, bool $holds = false): void
    {
        if (! $permit->node()->prevents_applicant_approval) {
            return;
        }

        $term = $this->applicantGuard->rejectingTerm($actor, $permit->case());

        if ($term !== null) {
            $message = $holds ? ApplicantApprovalGuard::holdMessage($term) : ApplicantApprovalGuard::approvalMessage($term);

            throw CaseOperationErrors::ofCase($message);
        }
    }

    /**
     * 申請の系統(一時保存の新規・上書き、申請)の 403(操作.md 7 節の 2 の 2)。申請の代理では申請者({@see PrincipalContext})を渡す。
     *
     * @throws AuthorizationException 申請できないとき
     */
    public function requireApplicable(AccessContext $context, int $flowId): void
    {
        if (! $this->applicableFlows->isApplicable(Requester::fromAccessContext($context), $context->date, $flowId)) {
            throw CaseOperationErrors::notApplicable();
        }
    }

    /**
     * 再申請の 403(判定の 1 か所 = {@see CaseEntryGate::requireUpdatable()}。表より先に見る)。判定する人 = {@see CaseProxyChecks::applicantJudgeOf()}。
     *
     * @throws AuthorizationException 許されないとき
     */
    public function requireUpdatable(AccessContext $context, WorkflowCase $case): void
    {
        $judge = $this->proxyChecks->applicantJudgeOf($context, $case);

        $this->gate->requireUpdatable(Requester::fromAccessContext($judge), $context->date, $case->workflow_flow_id);
    }

    /**
     * 案件がまだ無い操作(一時保存の新規・新規の申請)の表。案件が無いので案件についての役割は無い(表は「案件が無い」だけを見る)。
     *
     * @throws ValidationException 表で許されないとき(case)
     */
    public function permitWithoutCase(WorkflowOperation $operation): void
    {
        if (! $this->table->permits($operation, null, null, null, new CaseRoles(false, false, false, false, false))) {
            throw CaseOperationErrors::ofCase(CaseOperationErrors::NOT_PERMITTED);
        }
    }

    /**
     * 案件の行の鍵と閲覧(判定の順の 1)。
     *
     * @throws ModelNotFoundException<WorkflowCase> 無い・閲覧できないとき
     */
    private function lockVisible(WorkflowOperation $operation, AccessContext $context, int $caseId): WorkflowCase
    {
        $query = WorkflowCase::query();
        $locked = match ($operation) {
            WorkflowOperation::DeleteDraft => $query->lockForUpdate(),
            WorkflowOperation::Confirm => $query->sharedLock(),
            default => $query->lock('for no key update'),
        };
        $case = $locked->findOrFail($caseId);

        if (! $this->visibility->isVisible(Requester::fromAccessContext($context), $context->date, $case->id)) {
            throw (new ModelNotFoundException)->setModel(WorkflowCase::class, [$caseId]);
        }

        return $case;
    }

    /**
     * 一括承認の 1 件の鍵・閲覧と、自分を処理者として読んだ状況と今の組(選べなければ null)。
     *
     * @return array{CaseSituation, BulkApproval|null}
     *
     * @throws ModelNotFoundException<WorkflowCase> 無い・閲覧できないとき
     */
    private function lockBulkApproval(AccessContext $context, int $caseId): array
    {
        $case = $this->lockVisible(WorkflowOperation::Approve, $context, $caseId);
        $requester = Requester::fromAccessContext($context);
        $situation = $this->decider->situationsOf($requester, $context->date, [$case])[$case->id];

        return [$situation, $this->decider->bulkApprovalsOf($requester, $context->date, [$situation])[$case->id]];
    }

    /**
     * 操作するノードの行(この案件のノードでなければ 422 case = 判定の順の 4)。
     *
     * @throws ValidationException
     */
    private function nodeOf(WorkflowCase $case, int $nodeId): WorkflowCaseNode
    {
        return WorkflowCaseNode::query()->with('parent')->where('workflow_case_id', $case->id)->find($nodeId)
            ?? throw CaseOperationErrors::ofCase(CaseOperationErrors::NOT_PERMITTED);
    }

    /**
     * 再申請・取止めの操作するノード = 現在のノードの申請のノード(無ければ null = 表が拒む)。
     */
    private function applyNodeOf(CaseSituation $situation): ?WorkflowCaseNode
    {
        $applyNode = $situation->currentApplyNode();

        return $applyNode === null ? null : WorkflowCaseNode::query()->findOrFail($applyNode->id);
    }

    /**
     * 判定の順の 5(状態 × 役割の表)。P の本人の行を問わなければ許すときは 422 principal_user_id(代理元を選んだ誤りは
     * 選んだ処理担当者の誤りとして出すため = 設計/ワークフロー/案件の運用/案件の操作/同時実行と検査.md の 14.2 節の「処理者の決め方の境界」)。
     * 案件の管理者の役割(保留解除の行 = 設計/ワークフロー/案件の運用/案件の操作/操作.md の 8 節)は案件のフローの operate で決まる。
     *
     * @throws ValidationException 表で許されないとき(case)・処理担当者の誤り(principal_user_id)
     */
    private function requirePermitted(WorkflowOperation $operation, AccessContext $context, CaseSituation $situation, ?int $nodeId): void
    {
        $requester = Requester::fromAccessContext($context);
        $date = $context->date;
        $operates = $this->flowPermissions->canOperate($requester, $date, $situation->case->workflow_flow_id);

        if ($this->decider->permitsIn($operation, $requester, $date, $situation, $nodeId, canOperate: $operates)) {
            return;
        }

        $withoutOwnRow = $this->decider->permitsIn(
            $operation, $requester, $date, $situation, $nodeId, withoutOwnRow: true, canOperate: $operates,
        );

        throw $withoutOwnRow
            ? CaseOperationErrors::principal()
            : CaseOperationErrors::ofCase(CaseOperationErrors::NOT_PERMITTED);
    }
}
