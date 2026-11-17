<?php

namespace App\Queries\Records\Api;

use App\Models\Records\Record;
use App\Models\Records\RecordRevision;
use App\Queries\Records\RecordQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 更新履歴の版の読み取りの公開の口(主体・認可・ロック・結果・失敗の正本 = docs の 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 4.1 節の表)。
 * 申請の詳細が使う口は 設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 2 節。
 */
class RecordRevisionQuery
{
    public function __construct(private RecordQuery $records) {}

    /**
     * 判定をしない・鍵を取らない(呼び手が書いた直後に、同じトランザクションで、行の鍵を持ったまま呼ぶ)。
     *
     * @return int|null そのレコードの最新の版の id(版が無ければ null)
     *
     * @throws ModelNotFoundException<Record> レコードが無い・明細の行のとき
     */
    public function latestId(int $recordId): ?int
    {
        $record = $this->records->topLevel($recordId);
        $id = RecordRevision::query()->where('record_id', $record->id)->orderByDesc('revision')->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * 判定をしない(呼び手が閲覧を判定済み)。
     *
     * @return bool 同じレコードに、その版より後の版があるか
     *
     * @throws ModelNotFoundException<RecordRevision> 版が無いとき
     */
    public function hasRevisionAfter(int $revisionId): bool
    {
        $revision = RecordRevision::query()->findOrFail($revisionId);

        return RecordRevision::query()->where('record_id', $revision->record_id)
            ->where('revision', '>', $revision->revision)->exists();
    }
}
