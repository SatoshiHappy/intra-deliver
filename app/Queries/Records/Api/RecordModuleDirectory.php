<?php

namespace App\Queries\Records\Api;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldOption;
use App\Models\Records\RecordModule;
use App\Queries\Records\RecordDefinitionQuery;
use App\Queries\Records\RecordModuleGate;
use App\Queries\Records\RecordModuleVisibilityStopCatalog;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 業務モジュールの定義の読み取りの口(業務モジュールの一覧・表示の止め・項目と選択肢の名前。主体・認可・検証の範囲・ロック・原子性・結果・失敗の
 * 正本 = docs の 設計/ワークフロー/業務データとの接続/公開の口/表示と関係の口.md の 7.3 の表の RecordModuleDirectory の 3 行)。
 * どれもレコードを返さない。鍵を取らない。
 *
 * @phpstan-type FieldName array{code: string, name: string, type: string, isMultiple: bool,
 *     options: list<array{code: string, name: string}>, fields: list<mixed>}
 */
class RecordModuleDirectory
{
    public function __construct(
        private RecordModuleGate $gate,
        private RecordModuleVisibilityStopCatalog $visibilityStops,
        private RecordDefinitionQuery $definitions,
    ) {}

    /**
     * 主体の判定をしない。定義の読み取りで、レコードを返さない。
     *
     * 親の無い業務モジュール全部のコードと名前(表示の止めのある業務モジュールも含む)。並び = sort_key・code(管理の画面の参照先の候補と同じ)。
     *
     * @return list<array{code: string, name: string}>
     */
    public function topLevelModules(): array
    {
        return array_values(RecordModule::query()->whereNull('parent_record_module_id')
            ->orderBy('sort_key')->orderBy('code')->get(['code', 'name'])
            ->map(fn (RecordModule $module) => ['code' => $module->code, 'name' => $module->name])->all());
    }

    /**
     * 主体の判定をしない。
     *
     * 業務モジュールの表示の止めの理由か null(止めの実装を登録の順に束ねる内部の 1 か所 {@see RecordModuleVisibilityStopCatalog} を通す)。
     *
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無い・明細のモジュールのとき
     */
    public function visibilityStopReason(string $moduleCode): ?string
    {
        return $this->visibilityStops->stopReason($this->gate->topLevelModule($moduleCode)->id);
    }

    /**
     * 主体の判定をしない。定義の読み取りで、レコードを返さない。
     *
     * 項目の並び(定義と同じ = {@see RecordDefinitionQuery::load()})で、項目のコード・名前・型・複数を選べるかと、選択肢のコードと名前
     * (選択肢の並び。選択でない項目は空)。明細の項目は明細のモジュールの項目を fields に 1 段だけ持つ(ほかの項目の fields は空)。
     *
     * @return list<FieldName>
     *
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無い・明細のモジュールのとき
     */
    public function fieldNames(string $moduleCode): array
    {
        $module = $this->definitions->load($this->gate->topLevelModule($moduleCode));

        $fields = $module->recordFields->map(fn (RecordField $field) => $this->fieldName($field, true));

        return array_values($fields->all());
    }

    /**
     * @return FieldName
     */
    private function fieldName(RecordField $field, bool $withChildren): array
    {
        $child = $withChildren && $field->type === RecordFieldType::Details ? $field->childRecordModule : null;

        return [
            'code' => $field->code,
            'name' => $field->name,
            'type' => $field->type->value,
            'isMultiple' => $field->is_multiple,
            'options' => array_values($field->recordFieldOptions
                ->map(fn (RecordFieldOption $option) => ['code' => $option->code, 'name' => $option->name])->all()),
            'fields' => $child === null ? [] : array_values($child->recordFields
                ->map(fn (RecordField $childField) => $this->fieldName($childField, false))->all()),
        ];
    }
}
