<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowOperation;
use App\Models\Workflow\WorkflowCase;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 案件の削除(案件操作。画面の語は申請の削除。docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.7 節。判定の順は同じ文書の 15.1 節 =
 * {@see CaseManagerGate})。案件の行を消し、部品は外部キーの連鎖で消える(設計/ワークフロー/案件の運用/データモデル/表.md の 3.13 節)。業務データの
 * レコードと通知は残す。履歴を書かず、通知の 1 か所を呼ばない(設計/ワークフロー/案件の運用/案件の操作/操作.md の 11 節)。
 */
class DeleteCase
{
    public function __construct(private CaseManagerGate $gate) {}

    /**
     * @param  int  $revision  画面を開いた時の改訂番号
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できないとき
     * @throws AuthorizationException 案件のフローの operate を許されないとき
     * @throws ValidationException 改訂番号・表(case)の誤り
     */
    public function execute(AccessContext $context, int $caseId, int $revision): void
    {
        DB::transaction(function () use ($context, $caseId, $revision): void {
            $permit = $this->gate->lockAndPermit(WorkflowOperation::DeleteCase, $context, $caseId, $revision);

            $permit->case()->delete();
        });
    }
}
