<?php

namespace App\Actions\Records;

use App\Actions\Records\Api\RecordDetailRowInput;
use App\Enums\Records\RecordFieldType;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use Illuminate\Database\Eloquent\Collection;

/**
 * 明細の入力を、保存する行(作業中の値)と消す行に分ける(docs の 設計/業務データの入れ物/項目と規則/README.md 6 節・設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 9.2 の 3)。
 *
 * 入力に明細の項目のキーが無ければ、今の行のまま(残る行として検証する)。キーがあれば、その配列が行の全体: id のある行はその行を変え、
 * id の無い行は作り、配列に無い今の行は消す。id は、その親のレコードの、その明細のモジュールの行に限る。同じ id の 2 度目と、
 * 登録での id と、100 行を超える配列を拒む。行の順 = 配列の順(sort_key = 0 からの添字)。
 *
 * @phpstan-type DetailPlan array{drafts: array<string, list<RecordDraft>>, removed: list<Record>}
 */
class RecordDetailDrafts
{
    use NormalizesInput;

    /** 1 つの明細の項目の行の数の上限 */
    public const MAX_ROWS = 100;

    /**
     * @param  RecordModule  $module  定義を読み込んだ業務モジュール
     * @param  Record|null  $parent  今の親のレコード(null = 登録)
     * @param  array<array-key, list<RecordDetailRowInput>>  $details  明細の入力
     * @param  array<string, string>  $errors
     * @return DetailPlan
     */
    public function build(RecordModule $module, ?Record $parent, array $details, array &$errors): array
    {
        $fields = $module->recordFields->where('type', RecordFieldType::Details);
        $plan = ['drafts' => [], 'removed' => []];

        foreach (array_keys($details) as $code) {
            if (! $fields->contains(fn (RecordField $field) => $field->code === (string) $code)) {
                $errors["details.{$code}"] ??= '明細の項目がありません';
            }
        }

        $currentRows = $this->currentRows($fields, $parent);

        foreach ($fields as $field) {
            $child = $field->childRecordModule;

            if ($child === null) {
                continue;
            }

            $current = $currentRows[$child->id] ?? new Collection;
            $rows = array_key_exists($field->code, $details) ? $this->fromInput($field, $child, $parent, $current, $details[$field->code], $errors) : $this->unchanged($field, $child, $current);
            $plan['drafts'][$field->code] = $rows['drafts'];
            $plan['removed'] = [...$plan['removed'], ...$rows['removed']];
        }

        return $plan;
    }

    /**
     * 入力に明細のキーが無いときの行(今の行のまま)。
     *
     * @param  Collection<int, Record>  $current
     * @return array{drafts: list<RecordDraft>, removed: list<Record>}
     */
    private function unchanged(RecordField $field, RecordModule $child, Collection $current): array
    {
        $drafts = [];

        foreach ($current->values() as $index => $row) {
            $drafts[] = new RecordDraft($child, $row, "details.{$field->code}.{$index}.", [], $row->sort_key, $row->parent_record_id);
        }

        return ['drafts' => $drafts, 'removed' => []];
    }

    /**
     * @param  Collection<int, Record>  $current
     * @param  list<RecordDetailRowInput>  $rows
     * @param  array<string, string>  $errors
     * @return array{drafts: list<RecordDraft>, removed: list<Record>}
     */
    private function fromInput(RecordField $field, RecordModule $child, ?Record $parent, Collection $current, array $rows, array &$errors): array
    {
        if (count($rows) > self::MAX_ROWS) {
            $errors["details.{$field->code}"] ??= "{$field->name}は ".self::MAX_ROWS.' 行までにしてください';

            return ['drafts' => [], 'removed' => []];
        }

        $drafts = [];
        $used = [];

        foreach ($rows as $index => $row) {
            $existing = $row->id === null ? null : $current->find($row->id);
            $idError = $this->idError($row, $parent, $existing, $used);

            if ($idError !== null) {
                $errors["details.{$field->code}.{$index}.id"] ??= $idError;

                continue;
            }

            $used[] = $row->id;
            $values = $this->normalizeInput($row->values);
            $drafts[] = new RecordDraft($child, $existing, "details.{$field->code}.{$index}.", $values, $index, $parent?->id);
        }

        return ['drafts' => $drafts, 'removed' => array_values($current->reject(fn (Record $row) => in_array($row->id, $used, true))->all())];
    }

    /**
     * 行の id の誤り(無ければ null)。
     *
     * @param  list<int|null>  $used  前の行が使った id
     */
    private function idError(RecordDetailRowInput $row, ?Record $parent, ?Record $existing, array $used): ?string
    {
        return match (true) {
            $row->id === null => null,
            $parent === null => '新しいレコードの明細の行に id は設定できません',
            $existing === null => '明細の行が見つかりません',
            in_array($row->id, $used, true) => '同じ行が 2 度あります',
            default => null,
        };
    }

    /**
     * 親のレコードの今の明細の行(明細のモジュールの id → 行。並び = sort_key・id)を 1 本の問い合わせで読む。
     *
     * @param  Collection<int, RecordField>  $fields  明細の項目
     * @return array<int, Collection<int, Record>>
     */
    private function currentRows(Collection $fields, ?Record $parent): array
    {
        $childIds = array_values(array_filter($fields->pluck('child_record_module_id')->all()));

        if ($parent === null || $childIds === []) {
            return [];
        }

        $rows = [];

        foreach (Record::query()->whereIn('record_module_id', $childIds)->where('parent_record_id', $parent->id)->with('recordReferences')->orderBy('sort_key')->orderBy('id')->get() as $row) {
            $rows[$row->record_module_id] ??= new Collection;
            $rows[$row->record_module_id]->push($row);
        }

        return $rows;
    }
}
