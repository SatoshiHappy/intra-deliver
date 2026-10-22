<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowFlowAction;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Authz\Api\Authorization;
use App\Queries\Authz\Api\Requester;
use DateTimeInterface;

/**
 * workflow-flow の判定の 1 か所(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 3 節)。判定はフローのリソース URI で認可の公開の口を呼ぶ
 * (リソースの無いフローはどちらも禁止)。判定を求める者と基準日は呼び手が渡す。鍵を取らない。
 */
class FlowPermissions
{
    public function __construct(private Authorization $authorization) {}

    /**
     * read を許されるフローの id(昇順)。フローは数個なので、フローごとに 1 回判定する。
     *
     * @return list<int>
     */
    public function readableFlowIds(Requester $requester, DateTimeInterface $d): array
    {
        $codes = WorkflowFlow::query()->orderBy('id')->pluck('code', 'id')->all();
        $read = WorkflowFlowAction::Read;
        $readable = array_filter($codes, fn (mixed $code) => $this->allows($requester, $d, (string) $code, $read));

        return array_map('intval', array_keys($readable));
    }

    /**
     * フローの operate を許されるか(無いフローは偽)。
     */
    public function canOperate(Requester $requester, DateTimeInterface $d, int $flowId): bool
    {
        $code = WorkflowFlow::query()->whereKey($flowId)->value('code');

        return is_string($code) && $this->allows($requester, $d, $code, WorkflowFlowAction::Operate);
    }

    private function allows(
        Requester $requester,
        DateTimeInterface $d,
        string $flowCode,
        WorkflowFlowAction $action,
    ): bool {
        return $this->authorization->allowsUri($requester, WorkflowFlowAction::uri($flowCode), $action->value, $d);
    }
}
