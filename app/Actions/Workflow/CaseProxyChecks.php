<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowOperation;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Workflow\ApplicantRequester;
use App\Queries\Workflow\WorkflowProxies;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 案件の操作の代理の確かめ(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 7 節)。代理の表は {@see WorkflowProxies} だけで引き、判定の順は呼び手
 * ({@see CaseOperationGuard} と申請の系統の業務処理)が持つ。鍵を取らない(設計/ワークフロー/案件の運用/データモデル/README.md の 4.2 節)。
 */
class CaseProxyChecks
{
    public function __construct(
        private WorkflowProxies $proxies,
        private ApplicantRequester $applicantRequester,
    ) {}

    /**
     * 新規の申請・新規の一時保存の申請者(設計/ワークフロー/案件の運用/案件の操作/操作.md の 7 節。入力が無ければ自分)。
     *
     * @throws AuthorizationException ゲストのとき
     * @throws ValidationException 代理元でないとき(applicant_user_id)
     */
    public function applicantOf(AccessContext $context, int $flowId, ?int $applicantUserId): int
    {
        $userId = $context->userId ?? throw CaseOperationErrors::notApplicable();

        if ($applicantUserId === null) {
            return $userId;
        }

        $kind = WorkflowProxyKind::Apply;
        $isPrincipal = $this->proxies->isPrincipalOf($userId, $applicantUserId, $kind, $flowId, $context->date);

        if (! $isPrincipal) {
            throw CaseOperationErrors::applicantPrincipal();
        }

        return $applicantUserId;
    }

    /**
     * 案件の申請者(一時保存の持ち主)で判定する 403 の判定する人(式は入口と同じ {@see ApplicantRequester}。代理の行が無い組の 403・422 の分かれ方を
     * 変えない = 設計/ワークフロー/案件の運用/案件の操作/同時実行と検査.md の 14.2 節の「処理者の決め方の境界」)。
     */
    public function applicantJudgeOf(AccessContext $context, WorkflowCase $case): AccessContext
    {
        return $this->applicantRequester->of($context, $case->user_id, $case->workflow_flow_id);
    }

    /**
     * 処理者 P(設計/ワークフロー/案件の運用/案件の操作/README.md の 3.2 節・4.3 節。一時保存の持ち主の代理先かは閲覧の判定が見た)。
     *
     * @param  WorkflowNodeKind|null  $kind  操作するノードの種類(無ければ null = 効く代理が無い)
     *
     * @throws ValidationException 確かめに外れたとき(principal_user_id)
     * @throws LogicException 閲覧の判定を通った利用者がいないとき
     */
    public function processorOf(
        WorkflowOperation $operation,
        AccessContext $context,
        WorkflowCase $case,
        ?WorkflowNodeKind $kind,
        ?int $principalUserId,
    ): int {
        $userId = $context->userId ?? throw new LogicException('閲覧の判定を通った利用者がいない');
        $draftOperations = [WorkflowOperation::OverwriteDraft, WorkflowOperation::DeleteDraft];

        if (in_array($operation, [...$draftOperations, WorkflowOperation::Apply], true)) {
            return $case->user_id;
        }

        if ($principalUserId === null) {
            return $userId;
        }

        $proxyKind = $kind === null ? null : WorkflowProxies::kindForNode($kind);
        $flowId = $case->workflow_flow_id;

        $isPrincipal = $proxyKind !== null
            && $this->proxies->isPrincipalOf($userId, $principalUserId, $proxyKind, $flowId, $context->date);

        if (! $isPrincipal) {
            throw CaseOperationErrors::principal();
        }

        return $principalUserId;
    }
}
