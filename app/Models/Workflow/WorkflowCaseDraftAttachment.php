<?php

namespace App\Models\Workflow;

use App\Models\Upload\Upload;
use Database\Factories\Workflow\WorkflowCaseDraftAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 一時保存の添付(一時保存の案件の部品。案件と一緒に消える)。表 workflow_case_draft_attachments(docs の
 * 設計/ワークフロー/申請の周辺/データモデル.md の 3.2 節)。列 = 一時保存の案件・実体(台帳の行。1 つの実体を指す行は 1 つ)・
 * 添付の名前。行は追記と削除だけで、付けた人は台帳の上げた人で読む。持てるのは一時保存の案件だけ(業務処理の規則 =
 * 設計/ワークフロー/申請の周辺/添付/書く順と検査.md の 7 節)。
 */
#[Fillable(['workflow_case_id', 'upload_id', 'name'])]
class WorkflowCaseDraftAttachment extends Model
{
    /** @use HasFactory<WorkflowCaseDraftAttachmentFactory> */
    use HasFactory;

    /**
     * 一時保存の案件。
     *
     * @return BelongsTo<WorkflowCase, $this>
     */
    public function workflowCase(): BelongsTo
    {
        return $this->belongsTo(WorkflowCase::class);
    }

    /**
     * 実体(台帳の行)。
     *
     * @return BelongsTo<Upload, $this>
     */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }
}
