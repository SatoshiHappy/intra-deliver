<?php

namespace Tests\Support;

use App\Actions\Workflow\ApplicationInput;
use App\Actions\Workflow\ApplyCase;
use App\Actions\Workflow\CreateDraft;
use App\Actions\Workflow\DraftInput;
use App\Actions\Workflow\UpdateDraft;
use App\Models\Master\User;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRevision;
use App\Models\Upload\Upload;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseDraftAttachment;
use App\Models\Workflow\WorkflowFlow;
use Illuminate\Http\UploadedFile;

/**
 * 申請の側の添付のテストの手助け(docs の 設計/ワークフロー/申請の周辺/添付/書く順と検査.md の 9.1 節・9.2 節)。
 * 業務処理は本物を HTTP なしで呼び、実体のディスクは一時の置き場({@see BuildsUploads})。フローは {@see RunsWorkflowOperations} の既定。
 */
trait OperatesCaseAttachments
{
    use BuildsUploads;
    use RunsWorkflowOperations;

    /** テストのフローのコンテンツの業務モジュール */
    private const MODULE_CODE = 'project';

    protected function setUpAttachments(): void
    {
        $this->fakeUploadDisk();
        $this->limitUploads();
        $this->createFlow();
    }

    /**
     * 業務モジュールの「添付ファイルを使用」とフローの「添付を使う」を切り替える。
     */
    protected function switchAttachments(bool $module, bool $flow): void
    {
        RecordModule::query()->where('code', self::MODULE_CODE)->update(['is_attachment_enabled' => $module]);
        WorkflowFlow::query()->where('code', self::FLOW_CODE)->update(['is_attachment_enabled' => $flow]);
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    protected function draftWith(User $user, array $files = [], ?User $applicant = null): WorkflowCase
    {
        $input = new DraftInput('案件名', null, ['name' => '下書き'], [], attachFiles: $files);
        $caseId = app(CreateDraft::class)->execute($this->contextOf($user), self::FLOW_CODE, $input, $applicant?->id);

        return WorkflowCase::query()->findOrFail($caseId);
    }

    /**
     * @param  list<UploadedFile>  $files
     * @param  list<int>  $removeIds
     */
    protected function overwrite(User $user, WorkflowCase $draft, array $files = [], array $removeIds = []): void
    {
        $input = new DraftInput('案件名', null, ['name' => '上書き'], [], null, $files, $removeIds);

        app(UpdateDraft::class)->execute($this->contextOf($user), $draft->id, $this->revisionOf($draft), $input);
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    protected function applyWith(User $user, array $files): WorkflowCase
    {
        return $this->applyAs($user, input: $this->attachmentInput($files));
    }

    /**
     * @param  list<UploadedFile>  $files
     * @param  list<int>  $removeIds
     * @param  array<string, mixed>  $values  上書きする値
     */
    protected function applyFromDraft(
        User $user,
        WorkflowCase $draft,
        array $files = [],
        array $removeIds = [],
        array $values = [],
    ): WorkflowCase {
        $context = $this->contextOf($user);
        $input = $this->attachmentInput($files, $removeIds, $values);
        $date = $context->date->toDateString();
        app(ApplyCase::class)->executeFromDraft($context, $draft->id, $this->revisionOf($draft), $input, $date);

        return WorkflowCase::query()->findOrFail($draft->id);
    }

    /**
     * @param  list<UploadedFile>  $files
     * @param  list<int>  $removeIds
     * @param  array<string, mixed>  $values  上書きする値
     */
    protected function attachmentInput(array $files, array $removeIds = [], array $values = []): ApplicationInput
    {
        $base = $this->projectInput($values);

        return new ApplicationInput($base->name, $base->comment, $base->record, $files, $removeIds);
    }

    /**
     * @return list<array{string, int|null}> 一時保存の添付の行(付けた順)の [名前, 上げた人]
     */
    protected function draftRows(WorkflowCase $draft): array
    {
        return array_values(WorkflowCaseDraftAttachment::query()->with('upload')
            ->where('workflow_case_id', $draft->id)->orderBy('id')->get()
            ->map(fn (WorkflowCaseDraftAttachment $row) => [$row->name, $row->upload?->user_id])
            ->all());
    }

    /**
     * @return list<int> 一時保存の添付の行の id(付けた順)
     */
    protected function draftRowIds(WorkflowCase $draft): array
    {
        return array_values(WorkflowCaseDraftAttachment::query()->where('workflow_case_id', $draft->id)
            ->orderBy('id')->pluck('id')->all());
    }

    /**
     * @return list<int> 一時保存の添付の行の実体の id(付けた順)
     */
    protected function draftUploadIds(WorkflowCase $draft): array
    {
        return array_values(WorkflowCaseDraftAttachment::query()->where('workflow_case_id', $draft->id)
            ->orderBy('id')->pluck('upload_id')->all());
    }

    /**
     * @return list<array{string, int|null, int}> レコードの添付(付けた順)の [名前, 付けた人, 実体の id]
     */
    protected function recordRows(WorkflowCase $case): array
    {
        return array_values(RecordAttachment::query()->where('record_id', $case->record_id)->orderBy('id')->get()
            ->map(fn (RecordAttachment $row) => [$row->name, $row->user_id, $row->upload_id])
            ->all());
    }

    /**
     * @return list<array{int|null, int|null, int}> 案件のレコードの版(古い順)の [操作した人, 処理者, 添付の数]
     */
    protected function revisions(WorkflowCase $case): array
    {
        return array_values(RecordRevision::query()->where('record_id', $case->record_id)->orderBy('revision')->get()
            ->map(function (RecordRevision $row): array {
                $attachments = $row->snapshot['attachments'] ?? [];

                return [$row->user_id, $row->principal_user_id, is_array($attachments) ? count($attachments) : 0];
            })
            ->all());
    }

    /**
     * @param  list<int>  $uploadIds
     */
    protected function uploadCount(array $uploadIds): int
    {
        return Upload::query()->whereKey($uploadIds)->count();
    }
}
