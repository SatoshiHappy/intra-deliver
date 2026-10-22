<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRelatedColumn;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 関連レコードの列(docs の 設計/業務データの入れ物/レコードの共通機能/関連レコード.md の 2 節、表 record_related_columns): 入口ごとに選んだ列と、
 * 未選択のときの列(一覧の列の先頭 5 つ → 表示の項目 → 列なし)。管理の画面の「関連レコードの列」の props(業務モジュールの管理.md の 4 節・6 節)も作る。
 *
 * @phpstan-import-type EntryField from RecordRelatedEntries
 *
 * @phpstan-type FieldSummary array{code: string, name: string}
 * @phpstan-type RelatedColumnSetting array{entry: string, label: string, referencedModule: FieldSummary,
 *     columns: list<FieldSummary>, candidates: list<FieldSummary>}
 */
class RecordRelatedColumns
{
    /** 1 つの入口に選べる列の数 */
    public const MAX_COLUMNS = 5;

    public function __construct(
        private RecordRelatedEntries $entries,
        private RecordDefinitionQuery $definitions,
    ) {}

    /**
     * 表に出す列(5 つまで。並びの順)。選んだ列が無ければ未選択の列。
     *
     * @param  RecordModule  $module  行の業務モジュール({@see RecordDefinitionQuery::load()} で定義を読み込んだもの)
     * @return Collection<int, RecordField>
     */
    public function shown(RecordField $entryField, RecordModule $module): Collection
    {
        $fields = $this->definitions->valueFields($module)->keyBy('id');
        $selected = $this->selectedIds($entryField);

        if ($selected !== []) {
            return new Collection(array_values(array_filter(array_map(fn (int $id) => $fields->get($id), $selected))));
        }

        $listed = $fields->where('is_listed', true)->values();

        if ($listed->isNotEmpty()) {
            return $listed->take(self::MAX_COLUMNS)->values();
        }

        return $fields->where('is_title', true)->values();
    }

    /**
     * 管理の画面の「関連レコードの列」の行(業務モジュール = 参照元。明細のモジュールは行を持たない = 空)。
     *
     * @return list<RelatedColumnSetting>
     *
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無いとき
     */
    public function adminProps(string $moduleCode): array
    {
        $module = RecordModule::query()->where('code', $moduleCode)->firstOrFail();

        if ($module->parent_record_module_id !== null) {
            return [];
        }

        $candidates = $module->recordFields()->where('type', '<>', RecordFieldType::Details)
            ->orderBy('sort_key')->orderBy('id')->get();
        $byId = $candidates->keyBy('id');
        $summaries = array_values($candidates->map(fn (RecordField $field) => self::summary($field))->all());

        return array_map(fn (array $entry) => [
            'entry' => $entry['code'],
            'label' => $entry['label'],
            'referencedModule' => self::moduleSummary($entry['field']->referencedRecordModule),
            'columns' => array_values(array_map(
                fn (RecordField $field) => self::summary($field),
                array_filter(array_map(fn (int $id) => $byId->get($id), $this->selectedIds($entry['field']))),
            )),
            'candidates' => $summaries,
        ], $this->entries->inModule($module));
    }

    /**
     * 入口に選んだ列の項目の id(並びの順。空 = 未選択)。
     *
     * @return list<int>
     */
    private function selectedIds(RecordField $entryField): array
    {
        return array_values(RecordRelatedColumn::query()->where('record_field_id', $entryField->id)
            ->orderBy('sort_key')->orderBy('id')->pluck('column_record_field_id')
            ->map(fn (mixed $id) => (int) $id)->all());
    }

    /**
     * @return FieldSummary
     */
    private static function summary(RecordField $field): array
    {
        return ['code' => $field->code, 'name' => $field->name];
    }

    /**
     * 参照先の業務モジュール(他モジュール参照の項目は参照先を必ず持つ = 表の検査制約)。
     *
     * @return FieldSummary
     */
    private static function moduleSummary(?RecordModule $module): array
    {
        return ['code' => $module->code ?? '', 'name' => $module->name ?? ''];
    }
}
