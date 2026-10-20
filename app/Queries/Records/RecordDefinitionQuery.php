<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordRuleKind;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldOption;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRule;
use App\Models\Records\RelativeDefault;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * 業務モジュールの定義(項目・選択肢・参照先・明細のモジュール・規則。docs の 設計/業務データの入れ物/項目と規則/README.md、画面の型は 画面/README.md 1.2 の
 * RecordModuleDefinition)。業務処理と読み取りは {@see self::load()} で同じ形に読み込む。
 *
 * 並び: 項目は sort_key・id、選択肢は sort_key・code、規則は id(設計/業務データの入れ物/データモデル/表.md 2 節)。
 *
 * @phpstan-type FieldDefinition array{code: string, name: string, type: string, isRequired: bool, isMultiline: bool, isMultiple: bool, maxLength: int|null, defaultValue: string|int|bool|null, options: list<array{code: string, name: string}>, referencedModule: array{code: string, name: string}|null, childModule: array<string, mixed>|null, isListed: bool, isSearchable: bool, isTitle: bool, grantsRowAccess: bool}
 * @phpstan-type ModuleDefinition array{code: string, name: string, description: string|null, fields: list<FieldDefinition>, rules: list<array<string, mixed>>}
 */
class RecordDefinitionQuery
{
    public function __construct(private FieldValueParser $parser) {}

    /**
     * 業務モジュールに定義を読み込む(明細のモジュールの定義も)。{@see self::fieldDefinition()} が辿る関係をすべて先に読み込む
     * (明細のモジュールの項目の明細のモジュールも。明細は入れ子にならないので空だが、項目ごとに引かないように 1 回で読む)。
     */
    public function load(RecordModule $module): RecordModule
    {
        return $module->load([
            'recordFields' => fn (Relation $fields) => $fields->orderBy('sort_key')->orderBy('id'),
            'recordFields.recordFieldOptions' => fn (Relation $options) => $options->orderBy('sort_key')->orderBy('code'),
            'recordFields.referencedRecordModule',
            'recordFields.childRecordModule.recordFields' => fn (Relation $fields) => $fields->orderBy('sort_key')->orderBy('id'),
            'recordFields.childRecordModule.recordFields.recordFieldOptions' => fn (Relation $options) => $options->orderBy('sort_key')->orderBy('code'),
            'recordFields.childRecordModule.recordFields.referencedRecordModule',
            'recordFields.childRecordModule.recordFields.childRecordModule',
            'recordFields.childRecordModule.recordRules' => fn (Relation $rules) => $rules->orderBy('id'),
            'recordRules' => fn (Relation $rules) => $rules->orderBy('id'),
        ]);
    }

    /**
     * 値を持つ項目(明細を除く。{@see self::load()} で読み込んだ業務モジュール。項目の並び)。
     *
     * @return Collection<int, RecordField>
     */
    public function valueFields(RecordModule $module): Collection
    {
        return $module->recordFields->filter(fn (RecordField $field) => $field->type !== RecordFieldType::Details)->values();
    }

    /**
     * 明細の項目({@see self::load()} で読み込んだ業務モジュール。項目の並び)。
     *
     * @return Collection<int, RecordField>
     */
    public function detailsFields(RecordModule $module): Collection
    {
        return $module->recordFields->filter(fn (RecordField $field) => $field->type === RecordFieldType::Details)->values();
    }

    /**
     * 項目の集合のうち表示の項目(業務モジュールに 1 つまで。無ければ null)。
     *
     * @param  Collection<int, RecordField>  $fields
     */
    public function titleFieldOf(Collection $fields): ?RecordField
    {
        return $fields->first(fn (RecordField $field) => $field->is_title);
    }

    /**
     * 画面に渡す定義({@see self::load()} で読み込んだ業務モジュール)。相対の既定値は $now(省けば時計の今を 1 回だけ読む)で値にし、
     * 明細のモジュールにも同じ時点を渡す(項目と規則/README.md 3 節の最後の項)。
     *
     * @return ModuleDefinition
     */
    public function definition(RecordModule $module, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now(AccessContext::TIMEZONE);
        $codes = $module->recordFields->mapWithKeys(fn (RecordField $field) => [$field->id => $field->code])->all();

        return [
            'code' => $module->code,
            'name' => $module->name,
            'description' => $module->description,
            'fields' => array_values($module->recordFields->map(fn (RecordField $field) => $this->fieldDefinition($field, $now))->all()),
            'rules' => array_values($module->recordRules->map(fn (RecordRule $rule) => $this->ruleDefinition($rule, $codes))->all()),
        ];
    }

    /**
     * 項目 1 つの画面の形(選択肢・参照先・明細のモジュールを読み込んでおく。明細のモジュールは {@see self::load()} で定義も)。
     * 相対の既定値は $now で値にした固定の値と同じ形で渡す。
     *
     * @return FieldDefinition
     */
    public function fieldDefinition(RecordField $field, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now(AccessContext::TIMEZONE);
        $referenced = $field->referencedRecordModule;
        $child = $field->childRecordModule;
        $default = $field->default_value;

        return [
            'code' => $field->code,
            'name' => $field->name,
            'type' => $field->type->value,
            'isRequired' => $field->is_required,
            'isMultiline' => $field->is_multiline,
            'isMultiple' => $field->is_multiple,
            'maxLength' => $field->type === RecordFieldType::String ? $this->parser->maxLengthOf($field) : null,
            'defaultValue' => $default instanceof RelativeDefault ? $default->valueAt($now, $field->type) : $default,
            'options' => array_values($field->recordFieldOptions->map(fn (RecordFieldOption $option) => ['code' => $option->code, 'name' => $option->name])->all()),
            'referencedModule' => $referenced === null ? null : ['code' => $referenced->code, 'name' => $referenced->name],
            'childModule' => $child === null ? null : $this->definition($child, $now),
            'isListed' => $field->is_listed,
            'isSearchable' => $field->is_searchable,
            'isTitle' => $field->is_title,
            'grantsRowAccess' => $field->grants_row_access,
        ];
    }

    /**
     * @param  array<int, string>  $codes  項目の id → コード
     * @return array<string, mixed>
     */
    private function ruleDefinition(RecordRule $rule, array $codes): array
    {
        $common = ['id' => $rule->id, 'kind' => $rule->kind->value, 'target' => $codes[$rule->target_record_field_id] ?? null];

        return $rule->kind === RecordRuleKind::RequiredWhen
            ? [...$common, 'condition' => $rule->condition, 'message' => $rule->message]
            : [...$common, 'operator' => $rule->operator?->value, 'compared' => $codes[$rule->compared_record_field_id] ?? null, 'message' => $rule->message];
    }
}
