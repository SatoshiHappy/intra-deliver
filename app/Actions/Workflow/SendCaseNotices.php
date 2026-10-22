<?php

namespace App\Actions\Workflow;

use App\Actions\Notice\Api\NoticeMessage;
use App\Actions\Notice\Api\SendNotices;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowNoticeType;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Master\Api\EffectiveUsers;
use App\Queries\Workflow\WorkflowNoticeTargets;
use App\Queries\Workflow\WorkflowProxies;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * 案件の操作の結果から通知を送る 1 か所(docs の 設計/ワークフロー/案件の運用/通知.md の 3 節と 4 節)。操作の業務処理が書いた後に直接呼ぶ。
 * キューを使わない(通知の書き込みが失敗すれば操作ごと巻き戻すため = 設計/ワークフロー/案件の運用/案件の操作/操作.md の 11 節)。
 *
 * @phpstan-type Params array<string, string|int|bool|null>
 */
class SendCaseNotices
{
    public function __construct(
        private SendNotices $sendNotices,
        private WorkflowProxies $proxies,
        private EffectiveUsers $effectiveUsers,
    ) {}

    /**
     * @param  CaseContact|null  $contact  規則を通った連絡(無ければ null)
     * @param  CarbonImmutable  $date  操作の判定の d(代理先と宛先の有効性を見る今日 = 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.4 節)
     */
    public function execute(CaseOperationResult $result, ?CaseContact $contact, CarbonImmutable $date): void
    {
        $case = WorkflowCase::query()->findOrFail($result->caseId);
        $base = ['case_number' => $case->case_number, 'case_name' => $case->name];
        $nodeIds = [...array_keys($result->targetAdditions), ...array_keys($result->openedConfirmations)];
        $nodes = WorkflowCaseNode::query()->with('parent')->whereIn('id', $nodeIds)->get()->keyBy('id');

        $this->sendTargetAdditions($result, $date, $case, $base, $nodes);

        if ($result->completionReason !== null) {
            $params = [...$base, 'completion_reason' => $result->completionReason->value];
            $this->send($result, $date, WorkflowNoticeType::Result, $params, [$case->user_id]);
        }

        foreach ($result->openedConfirmations as $nodeId => $userIds) {
            $params = [...$base, 'step_name' => self::stepName(self::nodeOf($nodes, $nodeId))];
            $this->send($result, $date, WorkflowNoticeType::ConfirmRequest, $params, $userIds);
        }

        if ($contact !== null) {
            $params = [...$base, 'message' => $contact->message];
            $this->send($result, $date, WorkflowNoticeType::Contact, $params, $contact->userIds);
        }
    }

    /**
     * 処理対象者が増えたノードごとの処理依頼・担当変更・処理担当者の変更(増えた人と、その人を代理元とする今日に有効でノードの種類とフローに効く代理の代理先)。
     *
     * @param  Params  $base
     * @param  Collection<int, WorkflowCaseNode>  $nodes
     */
    private function sendTargetAdditions(
        CaseOperationResult $result,
        CarbonImmutable $date,
        WorkflowCase $case,
        array $base,
        Collection $nodes,
    ): void {
        $type = self::targetAdditionType($result->action);
        $comment = $type->carriesComment()
            ? WorkflowCaseHistory::query()->findOrFail($result->historyId)->comment
            : null;

        foreach ($result->targetAdditions as $nodeId => $userIds) {
            $node = self::nodeOf($nodes, $nodeId);
            $kind = WorkflowProxies::kindForNode($node->kind)
                ?? throw new LogicException("ノード {$nodeId} は処理対象者を持つ種類でない");
            $proxies = $this->proxies->proxyIdsOfAny($userIds, $case->workflow_flow_id, $kind, $date);
            // step_kind は代理の別と同じ語(申請・承認 = WorkflowProxyKind の値)
            $params = [...$base, 'step_name' => self::stepName($node), 'step_kind' => $kind->value];
            $params = $type->carriesComment() ? [...$params, 'comment' => $comment] : $params;
            $this->send($result, $date, $type, $params, [...$userIds, ...$proxies]);
        }
    }

    /**
     * 処理対象者が増えたときの種類(操作の種別で分ける = 通知.md の 3 節)。
     */
    private static function targetAdditionType(WorkflowAction $action): WorkflowNoticeType
    {
        return match ($action) {
            WorkflowAction::Transfer => WorkflowNoticeType::Transfer,
            WorkflowAction::Retarget, WorkflowAction::Reexpand => WorkflowNoticeType::Retarget,
            default => WorkflowNoticeType::ProcessRequest,
        };
    }

    /**
     * 宛先から除く人と今日に有効でない人を除いて 1 回送る。
     *
     * @param  Params  $params
     * @param  list<int>  $candidates
     */
    private function send(
        CaseOperationResult $result,
        CarbonImmutable $date,
        WorkflowNoticeType $type,
        array $params,
        array $candidates,
    ): void {
        $actor = $result->actor;
        $excluded = $type === WorkflowNoticeType::Contact
            ? [$actor->executorUserId]
            : [$actor->processorUserId, $actor->executorUserId];
        $recipients = $this->effectiveOn(array_values(array_diff($candidates, $excluded)), $date);
        $target = WorkflowNoticeTargets::CASE_TARGET_TYPE;
        $message = new NoticeMessage($type->value, $target, $result->caseId, $actor->executorUserId, $params);

        $this->sendNotices->execute($message, $recipients);
    }

    /**
     * @param  list<int>  $userIds
     * @return list<int>
     */
    private function effectiveOn(array $userIds, CarbonImmutable $date): array
    {
        return $userIds === [] ? [] : $this->effectiveUsers->idsOn(array_values(array_unique($userIds)), $date);
    }

    /**
     * @param  Collection<int, WorkflowCaseNode>  $nodes
     *
     * @throws LogicException 結果のノードが無いとき(同じトランザクションで書いたノードなので起きない)
     */
    private static function nodeOf(Collection $nodes, int $nodeId): WorkflowCaseNode
    {
        return $nodes->get($nodeId) ?? throw new LogicException("操作の結果のノード {$nodeId} が無い");
    }

    /**
     * 通知のステップ名(展開の行は親の名前 = 通知.md の 3 節)。
     */
    private static function stepName(WorkflowCaseNode $node): string
    {
        if ($node->parent_workflow_case_node_id === null) {
            return $node->name;
        }

        return ($node->parent ?? throw new LogicException("展開の行 {$node->id} の親が無い"))->name;
    }
}
