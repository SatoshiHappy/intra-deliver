<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Workflow\WorkflowCase;
use App\Support\AccessContext;
use DateTimeInterface;

/**
 * 申請の系統の 403 の判定する人の式の 1 か所(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.8 節)。入口・申請の画面の GET と
 * 業務処理の申請者の確かめが同じ式を呼び、判定する人を食い違わせない。鍵を取らない。
 */
class ApplicantRequester
{
    public function __construct(private WorkflowProxies $proxies) {}

    /**
     * 案件の申請者で判定する 403 の判定する人(設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.8 節)。
     *
     * @param  int  $applicantUserId  案件の申請者(一時保存の持ち主)
     */
    public function of(AccessContext $context, int $applicantUserId, int $flowId): AccessContext
    {
        $userId = $context->userId;
        $isPrincipal = $userId !== null && $applicantUserId !== $userId && $this->proxies
            ->isPrincipalOf($userId, $applicantUserId, WorkflowProxyKind::Apply, $flowId, $context->date);

        return $isPrincipal ? PrincipalContext::of($context, $applicantUserId) : $context;
    }

    /**
     * {@see self::of()} と同じ式を案件の並びに掛け、判定する人が持ち主になる案件だけを返す(一覧の行の can が使うので、問い合わせを案件の数に依らず 1 回にする)。
     *
     * @param  list<WorkflowCase>  $cases
     * @return array<int, int> 案件の id → 持ち主のユーザーの id
     */
    public function ownersAmong(int $userId, DateTimeInterface $date, array $cases): array
    {
        $flowIds = array_values(array_unique(array_map(fn (WorkflowCase $case) => $case->workflow_flow_id, $cases)));
        $principals = $this->proxies->principalIdsByFlow($userId, $flowIds, WorkflowProxyKind::Apply, $date);
        $owners = [];

        foreach ($cases as $case) {
            $principalIds = $principals[$case->workflow_flow_id] ?? [];

            if ($case->user_id !== $userId && in_array($case->user_id, $principalIds, true)) {
                $owners[$case->id] = $case->user_id;
            }
        }

        return $owners;
    }
}
