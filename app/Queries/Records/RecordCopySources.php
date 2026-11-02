<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use App\Models\Records\RecordRevision;
use App\Support\MixedValues;
use Illuminate\Database\Eloquent\Collection;

/**
 * 写しの初期値の写す元(レコード・版・画面の初期値の形の値)を、確かめの前の同じ形(項目ごとの読んだ値 = {@see RecordValueReader::rawValues()})に読む
 * (docs の 設計/業務データの入れ物/レコードの共通機能/複製.md の 2 節の「内部の写しの口」、
 * 設計/ワークフロー/申請の周辺/既存のデータからの申請/案件からの複製/写す値.md の 13.2)。今の定義の項目だけを読み、明細の行は id を持たない。確かめと表示名は {@see RecordCopyValues}。
 *
 * @phpstan-import-type RawValues from RecordValueReader
 * @phpstan-import-type ReferenceTarget from RecordValueReader
 * @phpstan-import-type StoredRowAccess from RecordRowAccessValues
 *
 * @phpstan-type CopyDetail array{field: RecordField, fields: Collection<int, RecordField>, rows: list<RawValues>}
 * @phpstan-type CopySource array{fields: Collection<int, RecordField>, raw: RawValues,
 *     details: array<string, CopyDetail>, rowAccess: StoredRowAccess|null}
 */
class RecordCopySources
{
    public function __construct(
        private RecordDefinitionQuery $definitions,
        private RecordValueReader $reader,
        private FieldValueParser $parser,
        private RecordRowAccessValues $rowAccess,
    ) {}

    /**
     * レコードの今の値(項目・明細の行・参照制限)。
     *
     * @param  RecordModule  $module  レコードの業務モジュール({@see RecordDefinitionQuery::load()} で読み込んだもの)
     * @return CopySource
     */
    public function ofRecord(RecordModule $module, Record $record): array
    {
        $fields = $this->definitions->valueFields($module);
        $children = Record::query()->where('parent_record_id', $record->id)->orderBy('sort_key')->orderBy('id')->get();
        $references = RecordReference::query()->whereIn('record_id', [$record->id, ...$children->modelKeys()])
            ->orderBy('sort_key')->get();
        $details = [];

        foreach ($this->detailsOf($module) as $code => [$field, $childFields, $childModuleId]) {
            $rows = $children->where('record_module_id', $childModuleId)->values();
            $details[$code] = ['field' => $field, 'fields' => $childFields, 'rows' => array_values($rows->map(
                fn (Record $row) => $this->storedRaw(
                    $childFields, $row->field_values ?? [], $this->targetsOf($references, $row->id),
                ),
            )->all())];
        }

        return [
            'fields' => $fields,
            'raw' => $this->storedRaw($fields, $record->field_values ?? [], $this->targetsOf($references, $record->id)),
            'details' => $details,
            'rowAccess' => $this->rowAccess->stored($record),
        ];
    }

    /**
     * 版の写しの値(snapshot。形 = docs の 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.1.1)。row_access の無い版は null。
     *
     * @param  RecordModule  $module  版の業務モジュール({@see RecordDefinitionQuery::load()} で読み込んだもの)
     * @return CopySource
     */
    public function ofRevision(RecordModule $module, RecordRevision $revision): array
    {
        $snapshot = $revision->snapshot ?? [];
        $fields = $this->definitions->valueFields($module);
        $snapshotDetails = MixedValues::arrayOf($snapshot['details'] ?? null);
        $details = [];

        foreach ($this->detailsOf($module) as $code => [$field, $childFields]) {
            $details[$code] = ['field' => $field, 'fields' => $childFields, 'rows' => array_values(array_map(
                fn (mixed $row) => $this->snapshotRaw($childFields, MixedValues::arrayOf($row)),
                MixedValues::arrayOf($snapshotDetails[$field->id] ?? null),
            ))];
        }

        return [
            'fields' => $fields,
            'raw' => $this->snapshotRaw($fields, $snapshot),
            'details' => $details,
            'rowAccess' => self::snapshotRowAccess($snapshot['row_access'] ?? null),
        ];
    }

    /**
     * 版の写しの参照制限(キーが無い = 参照制限の移しより前の版は null。相手の形でない参照者は捨てる)。
     *
     * @return StoredRowAccess|null
     */
    private static function snapshotRowAccess(mixed $stored): ?array
    {
        if (! is_array($stored)) {
            return null;
        }

        $viewers = array_map(RecordParty::of(...), MixedValues::arrayOf($stored['viewers'] ?? null));

        return [
            'restricted' => ($stored['restricted'] ?? false) === true,
            'viewers' => array_values(array_filter($viewers, fn (?array $viewer) => $viewer !== null)),
        ];
    }

    /**
     * 画面の初期値の形の値(項目のコード → 値)と明細(コード → 行)。渡された項目・明細だけを読む(写す項目を呼び手が選んだ値なので、
     * ほかの項目を空で返さない)。今の定義に無いコードは捨てる。参照制限は持たない。
     *
     * @param  RecordModule  $module  写す先の業務モジュール({@see RecordDefinitionQuery::load()} で読み込んだもの)
     * @param  array<array-key, mixed>  $values
     * @param  array<array-key, mixed>  $details
     * @return CopySource
     */
    public function ofValues(RecordModule $module, array $values, array $details): array
    {
        $fields = self::given($this->definitions->valueFields($module), array_keys($values));
        $copied = [];

        foreach ($this->detailsOf($module) as $code => [$field, $childFields]) {
            if (! array_key_exists($code, $details)) {
                continue;
            }

            $rows = array_map(
                fn (mixed $row) => MixedValues::arrayOf(MixedValues::arrayOf($row)['values'] ?? null),
                array_values(MixedValues::arrayOf($details[$code])),
            );
            $rowFields = self::given($childFields, array_merge([], ...array_map(array_keys(...), $rows)));
            $copied[$code] = [
                'field' => $field,
                'fields' => $rowFields,
                'rows' => array_map(fn (array $row) => $this->shownRaw($rowFields, $row), $rows),
            ];
        }

        return [
            'fields' => $fields, 'raw' => $this->shownRaw($fields, $values), 'details' => $copied, 'rowAccess' => null,
        ];
    }

    /**
     * 項目のうち、コードが渡されたもの(並びは定義のまま)。
     *
     * @param  Collection<int, RecordField>  $fields
     * @param  list<array-key>  $codes
     * @return Collection<int, RecordField>
     */
    private static function given(Collection $fields, array $codes): Collection
    {
        return $fields->filter(fn (RecordField $field) => in_array($field->code, $codes, true))->values();
    }

    /**
     * 明細の項目(コード → [明細の項目, 行の項目, 明細のモジュールの id])。明細のモジュールの無い項目は除く。
     *
     * @return array<string, array{RecordField, Collection<int, RecordField>, int}>
     */
    private function detailsOf(RecordModule $module): array
    {
        $details = [];

        foreach ($this->definitions->detailsFields($module) as $field) {
            $child = $field->childRecordModule;

            if ($child !== null) {
                $details[$field->code] = [$field, $this->definitions->valueFields($child), $child->id];
            }
        }

        return $details;
    }

    /**
     * 保存された値(field_values と参照の行の相手)。
     *
     * @param  Collection<int, RecordField>  $fields
     * @param  array<array-key, mixed>  $fieldValues
     * @param  array<int, list<ReferenceTarget>>  $targets  項目の id → 相手
     * @return RawValues
     */
    private function storedRaw(Collection $fields, array $fieldValues, array $targets): array
    {
        return $this->reader->rawValues(
            $fields,
            fn (RecordField $field) => $this->parser->stored($field, $fieldValues),
            fn (RecordField $field) => $targets[$field->id] ?? [],
        );
    }

    /**
     * レコードの参照の行の相手(項目の id → sort_key の順の相手)。
     *
     * @param  Collection<int, RecordReference>  $references
     * @return array<int, list<ReferenceTarget>>
     */
    private function targetsOf(Collection $references, int $recordId): array
    {
        $targets = [];

        foreach ($references->where('record_id', $recordId) as $reference) {
            $targets[$reference->record_field_id][] = $this->reader->target($reference);
        }

        return $targets;
    }

    /**
     * 版の写しの値(values = 項目の id → 値、references = 項目の id → 相手か相手の並び)。
     *
     * @param  Collection<int, RecordField>  $fields
     * @param  array<array-key, mixed>  $snapshot
     * @return RawValues
     */
    private function snapshotRaw(Collection $fields, array $snapshot): array
    {
        $references = MixedValues::arrayOf($snapshot['references'] ?? null);

        return $this->reader->rawValues(
            $fields,
            fn (RecordField $field) => $this->parser->stored($field, MixedValues::arrayOf($snapshot['values'] ?? null)),
            function (RecordField $field) use ($references): array {
                $value = $references[$field->id] ?? null;

                return $this->targets($field, is_array($value) && array_is_list($value) ? $value : [$value]);
            },
        );
    }

    /**
     * 画面の初期値の形の値(選択 = {code, name}、参照 = {id, …}・型「ユーザーと組織」は {type, id, …}、複数選べる参照は並び)。
     *
     * @param  Collection<int, RecordField>  $fields
     * @param  array<array-key, mixed>  $values
     * @return RawValues
     */
    private function shownRaw(Collection $fields, array $values): array
    {
        return $this->reader->rawValues(
            $fields,
            fn (RecordField $field) => $this->shownPlain($field, $values[$field->code] ?? null),
            function (RecordField $field) use ($values): array {
                $value = $values[$field->code] ?? null;

                $listed = $field->is_multiple && is_array($value) && array_is_list($value);

                return $this->targets($field, $listed ? $value : [$value]);
            },
        );
    }

    /**
     * 画面の値の通常の項目を保存された値の読み方にする(選択はコード、真偽は未設定を持たない)。
     */
    private function shownPlain(RecordField $field, mixed $value): mixed
    {
        return match ($field->type) {
            RecordFieldType::Choice => is_array($value) && is_string($value['code'] ?? null) ? $value['code'] : null,
            RecordFieldType::Boolean => (bool) $value,
            default => $value,
        };
    }

    /**
     * 相手の並び(相手の形の要素だけ。型「ユーザーと組織」は種類を保ち、ほかの参照の型は項目の型が種類)。
     *
     * @param  array<array-key, mixed>  $values
     * @return list<ReferenceTarget>
     */
    private function targets(RecordField $field, array $values): array
    {
        $found = [];

        foreach ($values as $value) {
            $item = MixedValues::arrayOf($value);
            $target = $field->type->hasPartyKind()
                ? RecordParty::of(['type' => $item['type'] ?? null, 'id' => $item['id'] ?? null])
                : (is_int($item['id'] ?? null) ? ['type' => $field->type->value, 'id' => $item['id']] : null);

            if ($target !== null) {
                $found[] = $target;
            }
        }

        return $found;
    }
}
