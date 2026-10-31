<?php

namespace App\Actions\Workflow;

use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 版の無効・有効(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md の 1 節。鍵 = データモデル/直列化と検査.md の 4.2 節)。
 */
class SetFlowVersionDeleted
{
    /**
     * @param  bool  $isDeleted  true = 無効にする、false = 有効に戻す
     *
     * @throws ModelNotFoundException<WorkflowFlow|WorkflowFlowVersion> フロー・そのフローの版が無いとき
     * @throws ValidationException 既に同じ状態のとき(version)
     */
    public function execute(string $flowCode, int $number, bool $isDeleted): void
    {
        DB::transaction(function () use ($flowCode, $number, $isDeleted) {
            $flow = WorkflowFlow::query()->where('code', $flowCode)->lock('for no key update')->firstOrFail();
            $version = WorkflowFlowVersion::query()
                ->where('workflow_flow_id', $flow->id)->where('number', $number)->firstOrFail();

            if ($version->is_deleted === $isDeleted) {
                $message = $isDeleted
                    ? FlowDefinitionErrors::VERSION_ALREADY_DELETED
                    : FlowDefinitionErrors::VERSION_ALREADY_ENABLED;

                throw ValidationException::withMessages(['version' => [$message]]);
            }

            $version->update(['is_deleted' => $isDeleted]);
        });
    }
}
