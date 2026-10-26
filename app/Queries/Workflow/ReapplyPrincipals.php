<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowNodeState;
use App\Enums\Workflow\WorkflowOperation;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\Api\SubjectGroupMembership;
use DateTimeInterface;

/**
 * 申請の代理先として再申請できる案件の申請者(docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.5 節)。詳細と一覧の行の can.reapply と
 * 再申請の画面の GET が同じここを通り、再申請の業務処理・入口と同じ判定にする。
 * 問い合わせは行の数に依らない: 自分が申請者でない再申請待ちの案件があるときだけ、代理元を 1 回と、代理元ごとの update の判定。
 */
class ReapplyPrincipals
{
    public function __construct(
        private CaseOperationDecider $decider,
        private WorkflowProxies $proxies,
        private SubjectGroupMembership $membership,
        private UpdatableFlows $updatableFlows,
    ) {}

    /**
     * @param  list<CaseSituation>  $situations  自分を処理者として読んだ状況
     * @return array<int, int> 案件の id → 代理元として再申請するときの申請者(できない案件は入れない)
     */
    public function of(Requester $requester, DateTimeInterface $date, array $situations): array
    {
        $userId = $this->membership->effectiveUserId($requester, $date);
        $waiting = $userId === null ? [] : $this->principalsWaiting($userId, $date, $situations);
        $updatable = $this->updatableByApplicant($requester, $date, $waiting);
        $principals = [];

        foreach ($waiting as $situation) {
            [$case, $applyNode] = [$situation->case, $situation->currentApplyNode()];
            $asApplicant = $situation->withProcessor($case->user_id);
            $permits = $applyNode !== null && $this->decider
                ->permitsIn(WorkflowOperation::Reapply, $requester, $date, $asApplicant, $applyNode->id);

            if ($permits && in_array($case->workflow_flow_id, $updatable[$case->user_id], true)) {
                $principals[$case->id] = $case->user_id;
            }
        }

        return $principals;
    }

    /**
     * 再申請待ちの案件のうち、申請者が自分の申請の代理元のもの(ほかの案件は代理先として再申請しない)。
     *
     * @param  list<CaseSituation>  $situations
     * @return list<CaseSituation>
     */
    private function principalsWaiting(int $userId, DateTimeInterface $date, array $situations): array
    {
        $waiting = array_filter($situations, fn (CaseSituation $situation) => $situation->case->user_id !== $userId
            && $situation->labelState() === WorkflowNodeState::Reapply);

        if ($waiting === []) {
            return [];
        }

        $flowIds = array_values(array_unique(array_map(fn (CaseSituation $s) => $s->case->workflow_flow_id, $waiting)));
        $principalsByFlow = $this->proxies->principalIdsByFlow($userId, $flowIds, WorkflowProxyKind::Apply, $date);
        $isPrincipals = fn (CaseSituation $situation) => in_array(
            $situation->case->user_id, $principalsByFlow[$situation->case->workflow_flow_id] ?? [], true,
        );

        return array_values(array_filter($waiting, $isPrincipals));
    }

    /**
     * 申請者ごとの、その人が update を許されるフロー。{@see CaseOperationDecider::isUpdatable()} と同じ式({@see UpdatableFlows::idsOf()})を、
     * 行ごとでなく申請者ごとに 1 回通す。
     *
     * @param  list<CaseSituation>  $situations
     * @return array<int, list<int>> 申請者のユーザーの id → フローの id
     */
    private function updatableByApplicant(Requester $requester, DateTimeInterface $date, array $situations): array
    {
        $flowIdsByApplicant = [];

        foreach ($situations as $situation) {
            $flowIdsByApplicant[$situation->case->user_id][] = $situation->case->workflow_flow_id;
        }

        $updatable = [];

        foreach ($flowIdsByApplicant as $applicantId => $flowIds) {
            $asApplicant = RecordApplyFlows::requesterAs($requester, $applicantId);
            $distinctFlowIds = array_values(array_unique($flowIds));
            $updatable[$applicantId] = $this->updatableFlows->idsOf($asApplicant, $date, $distinctFlowIds);
        }

        return $updatable;
    }
}
