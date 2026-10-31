<?php

namespace App\Actions\Workflow;

use App\Actions\Authz\Api\SelectionCondition;
use App\Models\Workflow\WorkflowFlow;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 参照者の指定の保存(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md の 5.2 節。鍵 = データモデル/直列化と検査.md の 4.2 節)。
 */
class SaveFlowReferrers
{
    public function __construct(private SelectionSubjectGroupWriter $subjectGroups) {}

    /**
     * @param  list<SelectionCondition>  $referrers  対象者の行(0 行 = 参照者なし)
     *
     * @throws ModelNotFoundException<WorkflowFlow> フローが無いとき
     * @throws ValidationException 相手が存在しない行(referrers.<添字>)
     */
    public function execute(string $flowCode, array $referrers): void
    {
        DB::transaction(function () use ($flowCode, $referrers) {
            $flow = WorkflowFlow::query()->where('code', $flowCode)->lock('for no key update')->firstOrFail();
            $name = "{$flow->name} / 参照者";
            $subjectGroupId = $this->subjectGroups
                ->repoint($flow->authz_subject_group_id, $referrers, $name, 'referrers');
            $flow->update(['authz_subject_group_id' => $subjectGroupId]);
        });
    }
}
