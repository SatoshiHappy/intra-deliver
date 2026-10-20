<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldOption;
use App\Models\Records\RecordModule;
use Illuminate\Support\Collection;

/**
 * 版を書いた時の定義の写し labels を今の定義から作り、版の labels を読む 1 か所(形 = docs の
 * 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.1.2 節)。fields = 定義の全部の項目(明細の項目は
 * 明細のモジュールの項目を入れ子で)、options = 選択の項目ごとに、写しの値(親と明細の行)で選ばれている選択肢だけ。
 *
 * @phpstan-import-type Snapshot from RecordSnapshots
 *
 * @phpstan-type FieldLabel array<string, mixed>
 * @phpstan-type FieldEntry array{id: int, code: string, name: string, type: RecordFieldType, multiple: bool,
 *     children: mixed}
 * @phpstan-type Labels array{fields: array<int, FieldLabel>, options: array<int, array<string, string>>}
 */
class RecordRevisionLabels
{
    /**
     * @param  RecordModule  $module  {@see RecordDefinitionQuery::load()} で定義を読み込んだ業務モジュール
     * @param  Snapshot  $snapshot
     * @return Labels
     */
    public function of(RecordModule $module, array $snapshot): array
    {
        $selected = [$snapshot['values']];

        foreach ($snapshot['details'] as $rows) {
            foreach ($rows as $row) {
                $selected[] = $row['values'];
            }
        }

        return ['fields' => $this->fields($module->recordFields), 'options' => $this->options($module, $selected)];
    }

    /**
     * 版の labels の fields(項目の id → 写し)を、並び(sort_key・id)の項目の並びに読む。形の違う写しは除く。
     *
     * @return list<FieldEntry>
     */
    public function entries(mixed $fields): array
    {
        $entries = [];

        foreach (is_array($fields) ? $fields : [] as $id => $label) {
            $entry = $this->entry($id, $label);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        usort($entries, fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_column($entries, 2);
    }

    /**
     * 項目 1 つの写しの並びの鍵(sort_key・id)と項目(id・コード・名前・型が無い写しは null)。
     *
     * @return array{int, int, FieldEntry}|null
     */
    private function entry(int|string $id, mixed $label): ?array
    {
        $label = is_int($id) && is_array($label) ? $label : [];
        $type = is_string($label['type'] ?? null) ? RecordFieldType::tryFrom($label['type']) : null;
        $code = $label['code'] ?? null;
        $name = $label['name'] ?? null;

        if (! is_int($id) || $type === null || ! is_string($code) || ! is_string($name)) {
            return null;
        }

        $sortKey = is_int($label['sort_key'] ?? null) ? $label['sort_key'] : 0;

        return [$sortKey, $id, [
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'multiple' => ($label['multiple'] ?? false) === true,
            'children' => $label['fields'] ?? [],
        ]];
    }

    /**
     * @param  Collection<int, RecordField>  $fields
     * @return array<int, FieldLabel>
     */
    private function fields(Collection $fields): array
    {
        $labels = [];

        foreach ($fields as $field) {
            $labels[$field->id] = [
                'code' => $field->code,
                'name' => $field->name,
                'type' => $field->type->value,
                'sort_key' => $field->sort_key,
                ...$this->displayAttributes($field),
            ];
        }

        return $labels;
    }

    /**
     * 値の表示に要る属性(型ごと。表.md の 2.1.2 節)。
     *
     * @return array<string, mixed>
     */
    private function displayAttributes(RecordField $field): array
    {
        return match ($field->type) {
            RecordFieldType::String => ['multiline' => $field->is_multiline],
            RecordFieldType::User, RecordFieldType::Department => ['multiple' => $field->is_multiple],
            RecordFieldType::Record => ['referenced_module' => $field->referencedRecordModule?->code],
            RecordFieldType::Details => [
                'fields' => $this->fields($field->childRecordModule->recordFields ?? new Collection),
            ],
            default => [],
        };
    }

    /**
     * @param  list<array<int, mixed>>  $selected  写しの値(親と明細の行の values)
     * @return array<int, array<string, string>> 選択の項目の id → 選ばれている選択肢のコード → 名前
     */
    private function options(RecordModule $module, array $selected): array
    {
        $choices = $module->recordFields->flatMap(fn (RecordField $field) => $field->type === RecordFieldType::Details
            ? ($field->childRecordModule->recordFields ?? [])
            : [$field])->filter(fn (RecordField $field) => $field->type === RecordFieldType::Choice);
        $options = [];

        foreach ($choices as $field) {
            $codes = array_filter(array_column($selected, $field->id), 'is_string');
            $names = $field->recordFieldOptions
                ->filter(fn (RecordFieldOption $option) => in_array($option->code, $codes, true))
                ->mapWithKeys(fn (RecordFieldOption $option) => [$option->code => $option->name])->all();

            if ($names !== []) {
                $options[$field->id] = $names;
            }
        }

        return $options;
    }
}
