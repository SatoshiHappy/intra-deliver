<?php

namespace App\Queries\Records;

use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordRevision;
use App\Queries\Upload\Api\UploadQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * レコードの添付と版が指す実体の読み取り(内部。docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/レコードの添付.md の
 * 1.1 節・6 節・8 節、更新履歴.md の 5.4 節)。主体の判定をしない(呼び手が判定済み)。版の写しは jsonb の包含で引く。
 */
class RecordAttachmentFiles
{
    public function __construct(private UploadQuery $uploads) {}

    /**
     * 持ち主の無い実体か(台帳に行があり、どの添付も指さず、どのレコードの版の写しにも無い)。重なった id は持ち主の無い実体に数えない。
     *
     * @param  list<int>  $uploadIds
     */
    public function areOwnerless(array $uploadIds): bool
    {
        if ($uploadIds === []) {
            return true;
        }

        return count(array_unique($uploadIds)) === count($uploadIds)
            && count($this->uploads->find($uploadIds)) === count($uploadIds)
            && ! RecordAttachment::query()->whereIn('upload_id', $uploadIds)->exists()
            && ! RecordRevision::query()->where(function (Builder $revisions) use ($uploadIds) {
                foreach ($uploadIds as $uploadId) {
                    $revisions->orWhereRaw('snapshot @> ?::jsonb', [self::containing($uploadId)]);
                }
            })->exists();
    }

    /**
     * レコードの削除で消す実体 = 今の添付と、全部の版の写しの添付の upload_id の和(重複を除く。昇順)。
     *
     * @return list<int>
     */
    public function uploadIdsOf(int $moduleId, int $recordId): array
    {
        $current = RecordAttachment::query()->where('record_module_id', $moduleId)->where('record_id', $recordId)
            ->pluck('upload_id')->all();
        $revised = RecordRevision::query()->where('record_id', $recordId)
            ->selectRaw("(jsonb_array_elements(snapshot->'attachments')->>'upload_id')::bigint AS upload_id")
            ->pluck('upload_id')->all();
        $ids = array_values(array_unique(array_map(intval(...), [...$current, ...$revised])));
        sort($ids);

        return $ids;
    }

    /**
     * そのレコードの今の添付(ルートの {attachment})。
     *
     * @return array{uploadId: int, name: string}
     *
     * @throws ModelNotFoundException<RecordAttachment> そのレコードの今の添付でないとき
     */
    public function attachment(int $moduleId, int $recordId, int $attachmentId): array
    {
        $attachment = RecordAttachment::query()->where('record_module_id', $moduleId)->where('record_id', $recordId)
            ->findOrFail($attachmentId);

        return ['uploadId' => $attachment->upload_id, 'name' => $attachment->name];
    }

    /**
     * 外した時点のファイル: そのレコードの版の写しにある upload_id と、それを含む最新の版の名前。
     *
     * @return array{uploadId: int, name: string}
     *
     * @throws ModelNotFoundException<RecordRevision> そのレコードのどの版の写しにも無いとき
     */
    public function revisionFile(int $recordId, int $uploadId): array
    {
        $revision = RecordRevision::query()->where('record_id', $recordId)
            ->whereRaw('snapshot @> ?::jsonb', [self::containing($uploadId)])
            ->orderByDesc('revision')->firstOrFail();

        foreach (($revision->snapshot ?? [])['attachments'] ?? [] as $attachment) {
            if (($attachment['upload_id'] ?? null) === $uploadId) {
                return ['uploadId' => $uploadId, 'name' => (string) $attachment['name']];
            }
        }

        throw (new ModelNotFoundException)->setModel(RecordRevision::class, [$uploadId]);
    }

    /**
     * 版の写しがその実体を指すかの包含の値(形 = データモデル/表.md の 2.1.1 節)。
     */
    private static function containing(int $uploadId): string
    {
        return (string) json_encode(['attachments' => [['upload_id' => $uploadId]]]);
    }
}
