<?php

namespace App\Queries\Workflow;

use App\Enums\Records\RecordModuleAction;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordFormQuery;
use DateTimeInterface;

/**
 * 再申請の 403 の判定の本体(docs の 設計/ワークフロー/案件の操作/操作.md 3.1 の 2): フローのコンテンツの業務モジュールの update を許されるか。
 * 判定する人は呼び手が引数で渡す(入口・画面の GET・業務処理が同じ口 {@see CaseOperationDecider::isUpdatable()} を通る)。鍵を取らない。
 */
class UpdatableFlows
{
    public function __construct(private RecordFormQuery $forms) {}

    /**
     * 渡したフローのうち、コンテンツの業務モジュールの update を判定を求める者が d に許されるもの(業務モジュールごとに 1 回判定する。空なら何も読まない)。
     *
     * @param  list<int>  $flowIds
     * @return list<int>
     */
    public function idsOf(Requester $requester, DateTimeInterface $date, array $flowIds): array
    {
        if ($flowIds === []) {
            return [];
        }

        $moduleCodes = WorkflowFlow::query()
            ->join('workflow_contents', 'workflow_contents.id', '=', 'workflow_flows.workflow_content_id')
            ->join('record_modules', 'record_modules.id', '=', 'workflow_contents.record_module_id')
            ->whereIn('workflow_flows.id', $flowIds)
            ->pluck('record_modules.code', 'workflow_flows.id');
        $allowedByModule = [];
        $updatable = [];

        foreach ($moduleCodes as $flowId => $moduleCode) {
            if ($allowedByModule[$moduleCode] ??= $this->forms->allows($requester, (string) $moduleCode, RecordModuleAction::Update, $date)) {
                $updatable[] = (int) $flowId;
            }
        }

        return $updatable;
    }
}
