<?php

namespace App\Queries\Records\Api;

use App\Models\Records\Record;
use App\Models\Records\RecordReference;
use App\Queries\Records\RecordQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * レコードの関係(他モジュール参照で指す・指される)の口(docs の 設計/ワークフロー/業務データとの接続/README.md 6 節。主体・認可・結果・失敗の正本 =
 * 同 公開の口.md 7 節の表)。返すのはレコードの id だけで、値は返さない(呼び手が案件の閲覧の判定で絞る)。
 */
class RecordRelations
{
    public function __construct(private RecordQuery $records) {}

    /**
     * 主体の判定をしない。呼び手が閲覧の判定を済ませてから呼ぶ。
     *
     * referenced = このレコードと明細の行が他モジュール参照で指すレコード、referencing = このレコードを他モジュール参照で指すレコード(明細の行から
     * 指すときはその親)。どちらも親の無いレコードの id の昇順で、重複と自分を除く。
     *
     * @return array{referenced: list<int>, referencing: list<int>}
     *
     * @throws ModelNotFoundException<Record> レコードが無い・明細の行のとき
     */
    public function relatedRecordIds(int $recordId): array
    {
        $record = $this->records->topLevel($recordId);
        $ownRecordIds = Record::query()->where('parent_record_id', $record->id)->orWhere('id', $record->id)->select('id');

        return [
            'referenced' => $this->topLevelIds(RecordReference::query()->whereIn('record_id', $ownRecordIds)->whereNotNull('referenced_record_id')->select('referenced_record_id'), $record->id),
            'referencing' => $this->topLevelIds(RecordReference::query()->where('referenced_record_id', $record->id)->select('record_id'), $record->id),
        ];
    }

    /**
     * レコードの id の問い合わせを、親の無いレコードの id(明細の行は親)に直す。重複と自分を除いた昇順。
     *
     * @param  Builder<RecordReference>  $recordIds  レコードの id を 1 列で返す問い合わせ
     * @return list<int>
     */
    private function topLevelIds(Builder $recordIds, int $self): array
    {
        return array_values(Record::query()->whereIn('id', $recordIds)->get(['id', 'parent_record_id'])
            ->map(fn (Record $related) => $related->parent_record_id ?? $related->id)
            ->reject(fn (int $id) => $id === $self)
            ->unique()
            ->sort()
            ->all());
    }
}
