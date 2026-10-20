<?php

namespace App\Actions\Records;

use App\Enums\Records\RecordRevisionAction;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordModule;
use App\Queries\Records\RecordAttachmentFiles;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 業務の画面で添付を 1 つ外す(内部。docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/レコードの添付.md の 1.2 節・5 節)。
 * uploads の行と実体は残す(版の写しが指し、外した時点のファイルを開けるため = 更新履歴.md の 5.4 節)。
 */
class DetachRecordAttachments
{
    public function __construct(
        private RecordAttachmentGate $gate,
        private RecordAttachmentFiles $files,
        private RecordRevisionWriter $revisions,
    ) {}

    /**
     * @throws ModelNotFoundException<RecordModule|Record|RecordAttachment> 業務モジュール・レコードが無い、そのレコードの今の添付でないとき
     * @throws AuthorizationException update を許されないとき
     * @throws ValidationException 持ち主があるとき(キー record)・使用しない業務モジュール(キー attachments)
     */
    public function execute(AccessContext $context, string $moduleCode, int $recordId, int $attachmentId): void
    {
        DB::transaction(function () use ($context, $moduleCode, $recordId, $attachmentId): void {
            [$module, $record] = $this->gate->lock($context, $moduleCode, $recordId, null);
            $this->files->attachment($record->id, $attachmentId);
            $this->gate->ensureWritable($module, $record, null);
            RecordAttachment::query()->whereKey($attachmentId)->delete();
            $this->revisions->write($record->id, RecordRevisionAction::Update, $context->userId, null);
        });
    }
}
