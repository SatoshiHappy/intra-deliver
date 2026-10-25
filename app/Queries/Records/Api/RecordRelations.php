<?php

namespace App\Queries\Records\Api;

use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use App\Queries\Records\RecordQuery;
use App\Queries\Records\RecordRelatedEntries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

/**
 * レコードの関係(他モジュール参照で指す・指される)の口(docs の 設計/ワークフロー/業務データとの接続/写しとつながる申請.md 6 節。主体・認可・結果・失敗の正本 =
 * 同 公開の口.md 7 節の表)。値は返さない(呼び手が案件の閲覧の判定で絞る)。
 *
 * @phpstan-import-type EntryField from RecordRelatedEntries
 *
 * @phpstan-type Bundle array{field: array{id: int, name: string, detailFieldName: string|null},
 *     module: array{id: int, name: string}, recordIds: non-empty-list<int>}
 */
class RecordRelations
{
    /** 関連レコード(参照の値の、自分でない側のレコード)の、親の無いレコードの id(明細の行は親) */
    private const TOP_LEVEL_ID_SQL = 'COALESCE(records.parent_record_id, records.id)';

    public function __construct(
        private RecordQuery $records,
        private RecordRelatedEntries $entries,
    ) {}

    /**
     * 主体の判定をしない。呼び手が案件の閲覧の判定で絞る(行の判定も、参照先の業務モジュールの read も見ない。項目と業務モジュールの
     * 名前は、絞った後に残った群の見出しにだけ使う)。呼び手は、このレコードの閲覧の判定(RecordViewAccess)が真のときだけ呼ぶ。
     *
     * 束の中身・並び・絞り = docs の 設計/ワークフロー/業務データとの接続/公開の口.md 7 節の referencedByField の行。並びと明細の親の項目は
     * このレコードの業務モジュールの関連レコードの入口と同じなので、入口の定義に任せる。指す行が無ければ入口を読まない。
     *
     * @return list<Bundle>
     *
     * @throws ModelNotFoundException<Record> レコードが無い・明細の行のとき
     */
    public function referencedByField(int $recordId): array
    {
        $record = $this->records->topLevel($recordId);
        $ownRecordIds = Record::query()->where('parent_record_id', $record->id)->orWhere('id', $record->id)
            ->select('id');
        $references = RecordReference::query()->whereIn('record_references.record_id', $ownRecordIds);
        $idsByField = self::idsByField($this->topLevelRelated($references, 'referenced_record_id', $record->id));

        if ($idsByField === []) {
            return [];
        }

        $module = $record->recordModule ?? throw new LogicException("レコード {$record->id} の業務モジュールが無い");
        $bundles = [];

        foreach ($this->entries->inModule($module) as $entry) {
            if (isset($idsByField[$entry['field']->id])) {
                $referencedModule = $entry['field']->referencedRecordModule
                    ?? throw new LogicException("他モジュール参照の項目 {$entry['field']->id} の参照先が無い");
                $bundles[] = $this->bundle($entry, $referencedModule, $idsByField[$entry['field']->id]);
            }
        }

        return $bundles;
    }

    /**
     * 主体の判定をしない(行の判定も、参照元の業務モジュールの read も見ない)。呼び手が案件の閲覧の判定で絞り、項目と業務モジュールの
     * 名前は、絞った後に残った群の見出しにだけ使う。呼び手は、このレコードの閲覧の判定(RecordViewAccess)が真のときだけ呼ぶ。
     *
     * 束の中身・並び・絞り = docs の 設計/ワークフロー/業務データとの接続/公開の口.md 7 節の referencingByField の行。並びと
     * 明細の親の項目は関連レコードの入口と同じなので、入口の定義に任せる。指す行が無ければ入口を読まない(参照元の無いレコードが多い)。
     *
     * @param  list<int>|null  $recordFieldIds  絞る項目の id(null は絞らない)
     * @return list<Bundle>
     *
     * @throws ModelNotFoundException<Record> レコードが無い・明細の行のとき
     */
    public function referencingByField(int $recordId, ?array $recordFieldIds = null): array
    {
        $record = $this->records->topLevel($recordId);
        $idsByField = $this->referencingIdsByField($record->id, $recordFieldIds);

        if ($idsByField === []) {
            return [];
        }

        $bundles = [];

        foreach ($this->entries->referencing($record->record_module_id) as $entry) {
            if (isset($idsByField[$entry['field']->id])) {
                $bundles[] = $this->bundle($entry, $entry['module'], $idsByField[$entry['field']->id]);
            }
        }

        return $bundles;
    }

    /**
     * このレコードを指す参照の値の、項目の id → 親の無いレコードの id の昇順。項目で絞るときは問い合わせで絞る。
     *
     * @param  list<int>|null  $recordFieldIds
     * @return array<int, non-empty-list<int>>
     */
    private function referencingIdsByField(int $self, ?array $recordFieldIds): array
    {
        $references = RecordReference::query()->where('record_references.referenced_record_id', $self);

        if ($recordFieldIds !== null) {
            $references->whereIn('record_references.record_field_id', $recordFieldIds);
        }

        return self::idsByField($this->topLevelRelated($references, 'record_id', $self));
    }

    /**
     * 参照の値の関連レコード(参照の値の自分でない側。参照先でも参照元でもよい)を、親の無いレコードの id に直す 1 本の問い合わせ
     * (自分を除き、項目との組の重複を除いて id の昇順)。referencingByField と referencedByField が同じ読み方で一致するように、両方がここを通る。
     *
     * @param  Builder<RecordReference>  $references  参照の値の条件
     * @param  'record_id'|'referenced_record_id'  $relatedColumn  関連レコードを指す列
     * @return list<array{fieldId: int, recordId: int}>
     */
    private function topLevelRelated(Builder $references, string $relatedColumn, int $self): array
    {
        $rows = $references->join('records', 'records.id', '=', "record_references.{$relatedColumn}")
            ->whereRaw(self::TOP_LEVEL_ID_SQL.' <> ?', [$self])
            ->toBase()
            ->distinct()
            ->select('record_references.record_field_id')
            ->selectRaw(self::TOP_LEVEL_ID_SQL.' AS top_level_id')
            ->orderBy('top_level_id')
            ->orderBy('record_references.record_field_id')
            ->get();

        return array_values($rows->map(fn (object $row) => [
            'fieldId' => (int) $row->record_field_id,
            'recordId' => (int) $row->top_level_id,
        ])->all());
    }

    /**
     * 項目との組を、項目の id → レコードの id の昇順にする(組は id の昇順なので並びは保たれる)。
     *
     * @param  list<array{fieldId: int, recordId: int}>  $pairs
     * @return array<int, non-empty-list<int>>
     */
    private static function idsByField(array $pairs): array
    {
        $idsByField = [];

        foreach ($pairs as $pair) {
            $idsByField[$pair['fieldId']][] = $pair['recordId'];
        }

        return $idsByField;
    }

    /**
     * 入口(他モジュール参照の項目)と、recordIds の側の業務モジュールと、そのレコードの id を、束の形にする。
     *
     * @param  EntryField  $entry
     * @param  non-empty-list<int>  $recordIds
     * @return Bundle
     */
    private function bundle(array $entry, RecordModule $module, array $recordIds): array
    {
        return [
            'field' => [
                'id' => $entry['field']->id,
                'name' => $entry['field']->name,
                'detailFieldName' => $entry['detailsField']?->name,
            ],
            'module' => ['id' => $module->id, 'name' => $module->name],
            'recordIds' => $recordIds,
        ];
    }
}
