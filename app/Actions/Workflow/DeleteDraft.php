<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowOperation;
use App\Models\Workflow\WorkflowCase;
use App\Support\AccessContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 一時保存の削除(docs の 設計/ワークフロー/案件の操作/操作.md 3.4)。判定の順(3.1 の 2): 案件の行を FOR UPDATE で引き閲覧を判定(404)→ 改訂番号 → 表
 * (本人の一時保存。一時保存でない案件もここで 422 case)→ 案件の行を消す。申請できる人は求めない(申請できなくなった人も自分の一時保存を消せる)。
 * 一時保存の添付は、行が連鎖で消える前に実体の id を集め、案件の行の後に実体を消す(設計/ワークフロー/申請の周辺/添付/書く順と検査.md の 6 節)。
 */
class DeleteDraft
{
    public function __construct(
        private CaseOperationGuard $guard,
        private CaseDraftAttachments $attachments,
    ) {}

    /**
     * @param  int  $revision  画面を開いた時の改訂番号
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できないとき
     * @throws ValidationException 改訂番号・表の誤り(case)
     */
    public function execute(AccessContext $context, int $caseId, int $revision): void
    {
        DB::transaction(function () use ($context, $caseId, $revision): void {
            $case = $this->guard->lockAndPermit(WorkflowOperation::DeleteDraft, $context, $caseId, $revision)->case();
            $uploadIds = $this->attachments->uploadIdsOf($case);

            $case->delete();
            $this->attachments->discardUploads($uploadIds);
        });
    }
}
