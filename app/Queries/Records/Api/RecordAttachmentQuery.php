<?php

namespace App\Queries\Records\Api;

use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Queries\Records\RecordQuery;
use App\Queries\Upload\Api\UploadFile;
use App\Queries\Upload\Api\UploadQuery;
use App\Queries\Upload\Api\UploadResponses;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

/**
 * レコードの添付の読み取り(口の保証の正本 = docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/レコードの添付.md の 1.1 節の表)。
 * 判定しない(呼び手が閲覧を判定済み)。並びは新しい順(id の降順)。
 *
 * @phpstan-type AttachmentItem array{id: int, uploadId: int, name: string, size: int, mime: string, previewable: bool,
 *     userId: int|null, attachedAt: string|null, purgeDueOn: string|null}
 */
class RecordAttachmentQuery
{
    public function __construct(
        private RecordQuery $records,
        private UploadQuery $uploads,
        private UploadResponses $responses,
    ) {}

    /**
     * @param  int|null  $beforeId  続きを読むとき、前の結果の最後の添付の id(それより古いものを返す)
     * @return array{items: list<AttachmentItem>, hasMore: bool} userId = 付けた人(ゲストなら null)
     *
     * @throws ModelNotFoundException<Record> レコードが無い・明細の行のとき
     */
    public function forRecord(int $recordId, int $limit, ?int $beforeId = null): array
    {
        $moduleId = $this->records->topLevel($recordId)->record_module_id;
        $attachments = RecordAttachment::query()->where('record_module_id', $moduleId)->where('record_id', $recordId)
            ->when($beforeId !== null, fn ($query) => $query->where('id', '<', $beforeId))
            ->orderByDesc('id')->limit($limit + 1)->get();
        $page = $attachments->take($limit);
        $files = $this->uploads->find(array_values($page->pluck('upload_id')->all()));

        return [
            'items' => array_values($page->map(fn (RecordAttachment $item) => $this->item($item, $files))->all()),
            'hasMore' => $attachments->count() > $limit,
        ];
    }

    /**
     * @throws ModelNotFoundException<Record> レコードが無い・明細の行のとき
     */
    public function countFor(int $recordId): int
    {
        $moduleId = $this->records->topLevel($recordId)->record_module_id;

        return RecordAttachment::query()->where('record_module_id', $moduleId)->where('record_id', $recordId)->count();
    }

    /**
     * @param  array<int, UploadFile>  $files
     * @return AttachmentItem
     *
     * @throws LogicException 台帳の行が無いとき(外部キーの不変条件が崩れた)
     */
    private function item(RecordAttachment $attachment, array $files): array
    {
        $file = $files[$attachment->upload_id] ?? throw new LogicException("添付 {$attachment->id} の台帳の行が無い");

        return [
            'id' => $attachment->id,
            'uploadId' => $file->id,
            'name' => $attachment->name,
            'size' => $file->size,
            'mime' => $file->mime,
            'previewable' => $file->purgeDueOn === null && $this->responses->isPreviewable($file->mime),
            'userId' => $attachment->user_id,
            'attachedAt' => $attachment->created_at?->toIso8601String(),
            'purgeDueOn' => $file->purgeDueOn,
        ];
    }
}
