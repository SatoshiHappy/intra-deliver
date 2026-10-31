<?php

namespace App\Actions\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordReference;
use App\Queries\Records\FieldValueParser;
use App\Queries\Records\RecordParty;
use LogicException;

/**
 * 検証を通した 1 つのレコードを書く(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 9.2 の 9、値の形は 設計/業務データの入れ物/データモデル/値の形と直列化.md 3 節)。
 * 通常の項目の値は項目の id をキーにして field_values に(空は持たない。真偽はいつも持つ。定義に無いキーは除く)、参照の値は record_references に置く。
 * 明細の行は、新しい行と、値か並びか参照が今と違う行だけを書く(親はいつも書く)。定義に無いキーは差に数えず、書く行から除く。
 * 参照の相手は、型「ユーザーと組織」なら {type, id}(相手の種類で user_id か department_id)、ほかの参照の型なら id。
 *
 * @phpstan-import-type Party from RecordParty
 */
class RecordWriter
{
    public function __construct(private FieldValueParser $parser) {}

    /**
     * @param  Record|null  $parent  明細の行の親のレコード(親なら null)
     * @param  int|null  $userId  作成者・更新者(null = ゲスト)
     * @param  bool  $always  変わっていなくても書く(親のレコード)
     * @param  bool|null  $rowAccessRestricted  親のレコードのチェック「参照を制限する」(明細の行は null = 書かない。列の既定の偽のまま)
     */
    public function write(
        RecordDraft $draft,
        ?Record $parent,
        ?int $userId,
        bool $always,
        ?bool $rowAccessRestricted = null,
    ): Record {
        $fieldValues = $this->fieldValues($draft);
        $record = $draft->record ?? new Record([
            'record_module_id' => $draft->module->id,
            'parent_record_module_id' => $parent?->record_module_id,
            'parent_record_id' => $parent?->id,
            'created_by_user_id' => $userId,
        ]);

        if ($always || ! $record->exists || $this->differs($draft, $record, $fieldValues)) {
            $record->fill(['sort_key' => $draft->sortKey, 'field_values' => $fieldValues, 'updated_by_user_id' => $userId]);
            $record->fill($rowAccessRestricted === null ? [] : ['row_access_restricted' => $rowAccessRestricted]);
            $this->save($record);
        }

        $this->writeReferences($draft, $record);

        return $record;
    }

    private function save(Record $record): void
    {
        if ($record->exists) {
            $record->touch();
        } else {
            $record->save();
        }
    }

    /**
     * @return array<int, mixed> 項目の id → 値
     */
    private function fieldValues(RecordDraft $draft): array
    {
        $values = [];

        foreach ($draft->module->recordFields as $field) {
            $value = $draft->values[$field->code] ?? null;

            if ($field->type->isPlain() && $value !== null) {
                $values[$field->id] = $value;
            }
        }

        ksort($values);

        return $values;
    }

    /**
     * 今の行と、値・並び・参照のどれかが違うか。今の値は、今の定義の通常の項目だけを {@see FieldValueParser::stored()} で読んで比べる
     * (キーの無い真偽は偽 = 書く値と同じ)。定義に無い id のキー(消した項目のキー)は差に数えないので、触っていない明細の行は書き直さない。
     * そのキーは、その行が次に書かれるときに除く(書く値は今の定義の項目だけ = データモデル/値の形と直列化.md 3 節)。
     *
     * @param  array<int, mixed>  $fieldValues
     */
    private function differs(RecordDraft $draft, Record $record, array $fieldValues): bool
    {
        $stored = $record->field_values ?? [];
        $current = [];

        foreach ($draft->module->recordFields as $field) {
            $value = $field->type->isPlain() ? $this->parser->stored($field, $stored) : null;

            if ($value !== null) {
                $current[$field->id] = $value;
            }
        }

        ksort($current);

        return $current !== $fieldValues
            || $record->sort_key !== $draft->sortKey
            || $draft->module->recordFields->contains(fn (RecordField $field) => $field->type->isReference() && $record->referenceValue($field) !== ($draft->values[$field->code] ?? null));
    }

    private function writeReferences(RecordDraft $draft, Record $record): void
    {
        foreach ($draft->module->recordFields as $field) {
            if ($field->is_multiple) {
                $this->writeMultipleReferences($draft, $record, $field);
            } elseif ($field->type->isReference()) {
                $this->writeReference($draft, $record, $field);
            }
        }
    }

    /**
     * 1 つの値の参照を書く(null は行を消す)。
     *
     * @throws LogicException 値が項目の型の相手の形でも null でもないとき(検証を通った後に来ない = 呼び手の誤り)
     */
    private function writeReference(RecordDraft $draft, Record $record, RecordField $field): void
    {
        $target = $draft->values[$field->code] ?? null;
        $row = $draft->record?->referencesFor($field)[0] ?? null;

        if ($target === null) {
            $row?->delete();
        } elseif ($row === null) {
            RecordReference::query()->create(['record_module_id' => $record->record_module_id, 'record_id' => $record->id, 'record_field_id' => $field->id, ...$this->targetColumns($field, $target)]);
        } elseif ($draft->record->referenceValue($field) !== $target) {
            $row->update($this->targetColumns($field, $target));
        }
    }

    /**
     * 複数選べる参照の値を書く(項目と規則/README.md 5 節)。値が並びを含めて今と違うときだけ、項目の行を全部消してから入力の順に
     * sort_key = 0 からの添字で作り直す(並べ替えでも一意の違反を起こさない)。変わらなければ行に触れない。
     *
     * @throws LogicException 値が相手のリストでも null でもないとき(検証を通った後に来ない = 呼び手の誤り)
     */
    private function writeMultipleReferences(RecordDraft $draft, Record $record, RecordField $field): void
    {
        $value = $draft->values[$field->code] ?? null;

        if ($value !== null && (! is_array($value) || ! array_is_list($value))) {
            throw new LogicException("複数選べる参照の値がリストでない(検証の後に来ない): {$field->code}");
        }

        $targets = $value ?? [];

        if ($targets === ($draft->record?->referenceValue($field) ?? [])) {
            return;
        }

        RecordReference::query()->where('record_id', $record->id)->where('record_field_id', $field->id)->delete();

        foreach ($targets as $index => $target) {
            RecordReference::query()->create([
                'record_module_id' => $record->record_module_id,
                'record_id' => $record->id,
                'record_field_id' => $field->id,
                'sort_key' => $index,
                'is_multiple' => true,
                ...$this->targetColumns($field, $target),
            ]);
        }
    }

    /**
     * 参照の行の相手の列(1 行は 1 つの相手だけを指す)。型ごとの分岐は既定の枝に落とさず全部の型を挙げる
     * (新しい型を足したら静的解析が漏れを知らせる)。
     *
     * @return array{user_id: int|null, department_id: int|null, referenced_record_module_id: int|null, referenced_record_id: int|null}
     *
     * @throws LogicException 相手が項目の型の相手の形でない・参照でない型のとき(検証を通った後に来ない = 呼び手の誤り)
     */
    private function targetColumns(RecordField $field, mixed $target): array
    {
        $columns = [
            'user_id' => null,
            'department_id' => null,
            'referenced_record_module_id' => null,
            'referenced_record_id' => null,
        ];
        $party = RecordParty::of($target);
        $mismatch = new LogicException("参照の相手が項目の型の相手の形でない(検証の後に来ない): {$field->code}");

        return match ($field->type) {
            RecordFieldType::User => is_int($target) ? [...$columns, 'user_id' => $target] : throw $mismatch,
            RecordFieldType::Department => is_int($target) ? [...$columns, 'department_id' => $target] : throw $mismatch,
            RecordFieldType::UserOrDepartment => $party === null || $party !== $target ? throw $mismatch : [
                ...$columns,
                'user_id' => $party['type'] === RecordFieldType::User->value ? $party['id'] : null,
                'department_id' => $party['type'] === RecordFieldType::Department->value ? $party['id'] : null,
            ],
            RecordFieldType::Record => is_int($target) ? [
                ...$columns,
                'referenced_record_module_id' => $field->referenced_record_module_id,
                'referenced_record_id' => $target,
            ] : throw $mismatch,
            RecordFieldType::String,
            RecordFieldType::Integer,
            RecordFieldType::Decimal,
            RecordFieldType::Date,
            RecordFieldType::Datetime,
            RecordFieldType::Boolean,
            RecordFieldType::Choice,
            RecordFieldType::Details => throw $mismatch,
        };
    }
}
