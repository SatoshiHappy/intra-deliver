<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Queries\Authz\Api\Requester;
use DateTimeInterface;

/**
 * 一覧の一時保存の行の判定する人(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.8 節。式は {@see ApplicantRequester})。行の can を
 * 一時保存の入口({@see CaseEntryGate::authorizeDraftWrite()})と同じ人で求め、行のリンクと入口の結果を食い違わせない。
 */
class DraftRequesters
{
    public function __construct(
        private ApplicantRequester $applicantRequester,
        private ApplicableFlows $applicableFlows,
    ) {}

    /**
     * 判定する人が持ち主になる一時保存の行の、持ち主を処理者にした状況と、持ち主が申請できるか(持ち主が自分の行・一時保存でない行は入れない)。
     * 問い合わせは、代理元を 1 回と、持ち主ごとの申請できるフローの判定。
     *
     * @param  list<CaseSituation>  $situations
     * @return array<int, array{CaseSituation, bool}> 案件の id → [状況, 持ち主が申請できるか]
     */
    public function of(Requester $requester, DateTimeInterface $date, array $situations): array
    {
        $userId = $requester->userId;
        $isDraft = fn (CaseSituation $situation) => $situation->case->state === WorkflowCaseState::Draft;
        $drafts = array_filter($situations, $isDraft);

        if ($userId === null || $drafts === []) {
            return [];
        }

        $cases = array_values(array_map(fn (CaseSituation $situation) => $situation->case, $drafts));
        $owners = $this->applicantRequester->ownersAmong($userId, $date, $cases);
        $applicable = [];

        foreach ($this->flowIdsByOwner($drafts, $owners) as $ownerId => $flowIds) {
            $asOwner = RecordApplyFlows::requesterAs($requester, $ownerId);
            $applicable[$ownerId] = $this->applicableFlows->applicableIds($asOwner, $date, $flowIds);
        }

        $asOwners = [];

        foreach ($drafts as $situation) {
            $ownerId = $owners[$situation->case->id] ?? null;

            if ($ownerId !== null) {
                $canApply = in_array($situation->case->workflow_flow_id, $applicable[$ownerId], true);
                $asOwners[$situation->case->id] = [$situation->withProcessor($ownerId), $canApply];
            }
        }

        return $asOwners;
    }

    /**
     * 持ち主ごとのフローの id(判定する人が持ち主の行だけ)。
     *
     * @param  array<int, CaseSituation>  $drafts
     * @param  array<int, int>  $owners  案件の id → 持ち主
     * @return array<int, list<int>>
     */
    private function flowIdsByOwner(array $drafts, array $owners): array
    {
        $byOwner = [];

        foreach ($drafts as $situation) {
            $ownerId = $owners[$situation->case->id] ?? null;

            if ($ownerId !== null) {
                $byOwner[$ownerId][$situation->case->workflow_flow_id] = $situation->case->workflow_flow_id;
            }
        }

        return array_map(fn (array $flowIds) => array_values($flowIds), $byOwner);
    }
}
