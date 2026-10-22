<?php

namespace App\Queries\Records;

use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * レコード 1 件の読み取り(docs の 設計/業務データの入れ物/画面/業務の画面.md 2.3 の record と、2.2 の編集の record)。判定は呼び手の責任で、定義を読み込んだ業務モジュールを渡す
 * (入口 = 認可とメニュー/README.md 2.2 の詳細の read を {@see RecordPageQuery::open()} で通した後、ワークフローの口 = 閲覧の判定の後。
 * レコードの有無は判定の後に見る)。
 * 明細は明細の項目のコードごとに、行の並び(sort_key・id)の順。
 *
 * @phpstan-import-type ReferenceValue from RecordValueReader
 *
 * @phpstan-type DetailRow array{id: int, values: array<string, mixed>}
 */
class RecordQuery
{
    public function __construct(
        private RecordValueReader $reader,
        private RecordDefinitionQuery $definitions,
    ) {}

    /**
     * @param  RecordModule  $module  定義を読み込んだ業務モジュール(判定は呼び手の責任。入口 = read を通した後、ワークフローの口 = 閲覧の判定の後)
     * @return array{id: int, values: array<string, mixed>, details: array<string, list<DetailRow>>, createdAt: string|null, updatedAt: string|null, createdBy: ReferenceValue|null, updatedBy: ReferenceValue|null}
     *
     * @throws ModelNotFoundException<Record> レコードがその業務モジュールに無いとき(判定の後に見る)
     */
    public function show(Requester $requester, RecordModule $module, int $recordId, DateTimeInterface $date, string $localeCode): array
    {
        ['record' => $record, 'values' => $values, 'details' => $details] = $this->read($requester, $module, $recordId, $date, $localeCode);
        $users = $this->reader->users(array_values(array_filter([$record->created_by_user_id, $record->updated_by_user_id])), $date, $localeCode);

        return [
            'id' => $record->id,
            'values' => $values,
            'details' => $details,
            'createdAt' => $record->created_at?->toIso8601String(),
            'updatedAt' => $record->updated_at?->toIso8601String(),
            'createdBy' => $record->created_by_user_id === null ? null : $users[$record->created_by_user_id] ?? null,
            'updatedBy' => $record->updated_by_user_id === null ? null : $users[$record->updated_by_user_id] ?? null,
        ];
    }

    /**
     * 編集の画面の record(画面/業務の画面.md 2.2)。版は最初に読んだ親の行から作る(明細はその後に読むので、明細が版より新しくても
     * 古い版として拒まれる側に倒れる = 項目と規則/規則と業務処理/README.md 9.2 の 2)。引数と例外は {@see self::show()} と同じ。
     *
     * @param  RecordModule  $module  定義を読み込んだ業務モジュール(判定は呼び手の責任)
     * @return array{id: int, values: array<string, mixed>, details: array<string, list<DetailRow>>, version: string}
     *
     * @throws ModelNotFoundException<Record> レコードがその業務モジュールに無いとき(判定の後に見る)
     */
    public function editable(Requester $requester, RecordModule $module, int $recordId, DateTimeInterface $date, string $localeCode): array
    {
        ['record' => $record, 'values' => $values, 'details' => $details] = $this->read($requester, $module, $recordId, $date, $localeCode);

        return [
            'id' => $record->id,
            'values' => $values,
            'details' => $details,
            'version' => $record->version(),
        ];
    }

    /**
     * 複製の登録の画面の初期値(レコードの共通機能/複製.md の 1 節・2 節): 写すのはレコードの値(項目と明細)だけで、
     * 明細の行は id を持たない。参照の表示名は編集の画面の初期値と同じ形(読めない参照先は label が null)。
     * 判定(create と元の read・行の判定)は呼び手の責任。引数と例外は {@see self::show()} と同じ。
     *
     * @param  RecordModule  $module  定義を読み込んだ業務モジュール(判定は呼び手の責任)
     * @return array{id: int, values: array<string, mixed>,
     *     details: array<string, list<array{values: array<string, mixed>}>>}
     *
     * @throws ModelNotFoundException<Record> レコードがその業務モジュールに無いとき(判定の後に見る)
     */
    public function copySource(
        Requester $requester,
        RecordModule $module,
        int $recordId,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        $read = $this->read($requester, $module, $recordId, $date, $localeCode);

        return [
            'id' => $read['record']->id,
            'values' => $read['values'],
            'details' => array_map(self::withoutIds(...), $read['details']),
        ];
    }

    /**
     * 明細の行から id を外す(複製の初期値の行は新しい行)。
     *
     * @param  list<DetailRow>  $rows
     * @return list<array{values: array<string, mixed>}>
     */
    private static function withoutIds(array $rows): array
    {
        return array_map(fn (array $row) => ['values' => $row['values']], $rows);
    }

    /**
     * 親の無いレコード(明細の行は単独で扱わない = 項目と規則/README.md 6 節)。業務データの入れ物の公開の口がレコードの id から引く 1 か所。
     *
     * @throws ModelNotFoundException<Record> 無い・明細の行のとき
     */
    public function topLevel(int $recordId): Record
    {
        return Record::query()->whereNull('parent_record_id')->findOrFail($recordId);
    }

    /**
     * 親の行(最初に読む)と、その値・明細。show と editable が同じ引き方・組み立てを使う 1 か所。
     *
     * @return array{record: Record, values: array<string, mixed>, details: array<string, list<DetailRow>>}
     */
    private function read(Requester $requester, RecordModule $module, int $recordId, DateTimeInterface $date, string $localeCode): array
    {
        $record = Record::query()->where('record_module_id', $module->id)->findOrFail($recordId);
        $values = $this->reader->values(new Collection([$record]), $this->definitions->valueFields($module), $requester, $date, $localeCode)[$record->id];

        return ['record' => $record, 'values' => $values, 'details' => $this->details($module, $record, $requester, $date, $localeCode)];
    }

    /**
     * 明細の項目のコード → 行(定義を読み込んだ業務モジュール)。
     *
     * @return array<string, list<DetailRow>>
     */
    private function details(RecordModule $module, Record $record, Requester $requester, DateTimeInterface $date, string $localeCode): array
    {
        $details = [];

        foreach ($this->definitions->detailsFields($module) as $field) {
            $child = $field->childRecordModule;

            if ($child === null) {
                continue;
            }

            $rows = Record::query()->where('record_module_id', $child->id)->where('parent_record_id', $record->id)->orderBy('sort_key')->orderBy('id')->get();
            $values = $this->reader->values($rows, $this->definitions->valueFields($child), $requester, $date, $localeCode);
            $details[$field->code] = array_values($rows->map(fn (Record $row) => ['id' => $row->id, 'values' => $values[$row->id] ?? []])->all());
        }

        return $details;
    }
}
