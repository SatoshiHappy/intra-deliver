<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use Illuminate\Database\Eloquent\Builder;

/**
 * 関連レコードの入口の定義(docs の 設計/業務データの入れ物/レコードの共通機能/関連レコード.md の 1 節・2 節): 他モジュール参照の項目
 * (明細のモジュールの項目も)ごとに 1 つ。定義から作り、参照元の件数を見ない。行の業務モジュール = 項目の業務モジュール(明細の項目はその親)。
 * code = 行の業務モジュールの中の入口の項目のコード(明細の項目は <明細の項目>.<項目>)、key = <行の業務モジュール>.<code>(クエリ related の値)、
 * label = 項目の名前(明細の項目は <明細の名前>の<項目の名前>)。判定はしない(呼び手が read を見る)。
 *
 * @phpstan-type EntryField array{key: string, code: string, label: string, field: RecordField, module: RecordModule,
 *     detailsField: RecordField|null}
 * @phpstan-type NamedEntry array{key: string, code: string, label: string, field: RecordField, module: RecordModule,
 *     detailsField: RecordField|null, name: string}
 */
class RecordRelatedEntries
{
    /**
     * 業務モジュールを参照先にする入口(入口の並び = 行の業務モジュールの sort_key・コード → 項目の位置(明細の項目は親の明細の項目の位置))。
     * 名前 = 同じ行の業務モジュールに入口が 2 つ以上あれば「<業務モジュール>(<label>)」、そうでなければ業務モジュールの名前。
     *
     * @return list<NamedEntry>
     */
    public function referencing(int $referencedModuleId): array
    {
        $entries = $this->entriesOf(RecordField::query()->where('referenced_record_module_id', $referencedModuleId));
        usort($entries, fn (array $a, array $b) => $this->position($a) <=> $this->position($b));
        $counts = array_count_values(array_map(fn (array $entry) => $entry['module']->id, $entries));

        return array_map(fn (array $entry) => [...$entry, 'name' => $counts[$entry['module']->id] > 1
            ? "{$entry['module']->name}({$entry['label']})"
            : $entry['module']->name], $entries);
    }

    /**
     * 業務モジュール(親の無いもの)を行の業務モジュールにする入口(項目の位置の順。管理の画面の行と、列の選び方の入力の入口)。
     *
     * @return list<EntryField>
     */
    public function inModule(RecordModule $module): array
    {
        $children = RecordField::query()->select('child_record_module_id')
            ->where('record_module_id', $module->id)->where('type', RecordFieldType::Details);
        $entries = $this->entriesOf(RecordField::query()->where(fn (Builder $owners) => $owners
            ->where('record_module_id', $module->id)
            ->orWhereIn('record_module_id', $children)));
        usort($entries, fn (array $a, array $b) => $this->position($a) <=> $this->position($b));

        return $entries;
    }

    /**
     * 業務モジュール(親の無いもの)の入口のうち、code が一致するもの(無ければ null)。
     *
     * @return EntryField|null
     */
    public function find(RecordModule $module, mixed $code): ?array
    {
        if (! is_string($code)) {
            return null;
        }

        foreach ($this->inModule($module) as $entry) {
            if ($entry['code'] === $code) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * 他モジュール参照の項目を入口にする(行の業務モジュールと、明細の項目なら親の明細の項目をまとめて引く)。
     *
     * @param  Builder<RecordField>  $fields
     * @return list<EntryField>
     */
    private function entriesOf(Builder $fields): array
    {
        $fields = $fields->where('type', RecordFieldType::Record)
            ->with(['recordModule', 'referencedRecordModule'])->get();
        $childIds = $fields->map(fn (RecordField $field) => $field->recordModule?->parent_record_module_id === null
            ? null
            : $field->record_module_id)->filter()->unique()->values()->all();
        $detailsFields = RecordField::query()->with('recordModule')->where('type', RecordFieldType::Details)
            ->whereIn('child_record_module_id', $childIds)->get()->keyBy('child_record_module_id');
        $entries = [];

        foreach ($fields as $field) {
            $owner = $field->recordModule;
            $detailsField = $owner?->parent_record_module_id === null
                ? null
                : $detailsFields->get($field->record_module_id);
            $module = $detailsField === null ? $owner : $detailsField->recordModule;

            if ($module !== null) {
                $entries[] = $this->entry($field, $module, $detailsField);
            }
        }

        return $entries;
    }

    /**
     * @return EntryField
     */
    private function entry(RecordField $field, RecordModule $module, ?RecordField $detailsField): array
    {
        $code = $detailsField === null ? $field->code : "{$detailsField->code}.{$field->code}";

        return [
            'key' => "{$module->code}.{$code}",
            'code' => $code,
            'label' => $detailsField === null ? $field->name : "{$detailsField->name}の{$field->name}",
            'field' => $field,
            'module' => $module,
            'detailsField' => $detailsField,
        ];
    }

    /**
     * 入口の並びの鍵(行の業務モジュール → 項目の位置。明細の項目は親の明細の項目の位置の後に、明細の中の位置)。
     *
     * @param  EntryField  $entry
     * @return list<int|string>
     */
    private function position(array $entry): array
    {
        $outer = $entry['detailsField'] ?? $entry['field'];
        $inner = $entry['detailsField'] === null ? [0, 0] : [$entry['field']->sort_key, $entry['field']->id];

        return [$entry['module']->sort_key, $entry['module']->code, $outer->sort_key, $outer->id, ...$inner];
    }
}
