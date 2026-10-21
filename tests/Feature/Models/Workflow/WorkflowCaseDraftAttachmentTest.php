<?php

namespace Tests\Feature\Models\Workflow;

use App\Models\Upload\Upload;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseDraftAttachment;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 一時保存の添付(docs の 設計/ワークフロー/申請の周辺/データモデル.md の 3.2 節・3.3 節・7 節): 1 つの実体を 2 つの行が指さない、
 * 名前は空にしない、案件を消すと行は連鎖で消え実体の台帳の行は残る、実体の台帳の行を先に消せない、無い案件を指せない。
 * 外部キーの削除の動作とリレーションは ForeignKeysTest。
 */
final class WorkflowCaseDraftAttachmentTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    public function test_rejects_the_same_upload_on_two_rows(): void
    {
        $attachment = WorkflowCaseDraftAttachment::factory()->createOne();

        $this->assertRejectedBy(
            'workflow_case_draft_attachments_upload_id_unique',
            fn () => WorkflowCaseDraftAttachment::factory()->createOne(['upload_id' => $attachment->upload_id]),
        );
    }

    public function test_rejects_an_empty_name(): void
    {
        $this->assertRejectedBy(
            'workflow_case_draft_attachments_name_check',
            fn () => WorkflowCaseDraftAttachment::factory()->createOne(['name' => '']),
        );
    }

    public function test_deleting_the_case_deletes_the_rows_and_keeps_the_uploads(): void
    {
        $case = WorkflowCase::factory()->draft()->createOne();
        $attachments = WorkflowCaseDraftAttachment::factory()->count(2)->create(['workflow_case_id' => $case->id]);

        $case->delete();

        foreach ($attachments as $attachment) {
            $this->assertModelMissing($attachment);
            $this->assertDatabaseHas('uploads', ['id' => $attachment->upload_id]);
        }
    }

    public function test_rejects_deleting_an_upload_that_a_row_points_at(): void
    {
        $attachment = WorkflowCaseDraftAttachment::factory()->createOne();

        $this->assertRejectedBy(
            'workflow_case_draft_attachments_upload_id_foreign',
            fn () => Upload::query()->whereKey($attachment->upload_id)->delete(),
        );
    }

    public function test_rejects_a_case_that_does_not_exist(): void
    {
        $missing = (int) DB::table('workflow_cases')->max('id') + 1;

        $this->assertRejectedBy(
            'workflow_case_draft_attachments_workflow_case_id_foreign',
            fn () => WorkflowCaseDraftAttachment::factory()->createOne(['workflow_case_id' => $missing]),
        );
    }
}
