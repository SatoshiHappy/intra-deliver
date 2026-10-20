<?php

namespace App\Actions\Records;

use App\Actions\Master\WritesCommonMaster;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordRuleKind;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldOption;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRule;
use App\Queries\Records\RecordDefinitionQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\ValidationException;

/**
 * 項目を変える(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/失敗と定義の変更.md 11 節。名前・必須・既定値・最大の文字数・選択肢・一覧と検索の旗・
 * 表示の項目・並び順。コード・型・複数行・複数・参照先・明細のモジュールは変えない)。属性を厳しくしても既存のレコードは書き換えない。
 *
 * 選択肢は配列の全体で、コードで今の選択肢と突き合わせる(名称・並びを変え、無い今の選択肢を消し、新しいものを作る)。消す選択肢を
 * 値に持つレコードか、そのコードを使う規則の条件があれば拒む(4 節。キー options)。共通マスタと業務モジュールの排他の勧告ロック({@see LocksRecordModule})の下で行う。
 */
class UpdateRecordField
{
    use LocksRecordModule;
    use NormalizesInput;
    use WritesCommonMaster;

    public function __construct(
        private RecordDefinitionQuery $definitions,
        private FieldDefinitionValidator $validator,
    ) {}

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ModelNotFoundException<RecordModule|RecordField> 業務モジュール・項目が無いとき
     * @throws ValidationException 規則に反するとき
     */
    public function execute(string $moduleCode, string $fieldCode, array $input): RecordField
    {
        $input = $this->normalizeInput($input);

        return $this->writeCommonMaster(function () use ($moduleCode, $fieldCode, $input) {
            $module = $this->definitions->load($this->lockRecordModuleExclusively($moduleCode));
            $field = $module->recordFields->first(fn (RecordField $field) => $field->code === $fieldCode) ?? throw (new ModelNotFoundException)->setModel(RecordField::class, [$fieldCode]);
            $validated = $this->validator->validateUpdate($module, $field, $input);
            $codes = array_map(fn (array $option) => $option['code'], $validated['options']);
            $removed = array_values(array_diff($field->recordFieldOptions->pluck('code')->all(), $codes));

            if ($removed !== [] && $this->optionsInUse($module, $field, $removed)) {
                throw ValidationException::withMessages(['options' => ['削除する選択肢が、レコードの値か規則の条件で使用されています']]);
            }

            $field->update($validated['attributes']);
            $this->replaceOptions($field, $validated['options'], $removed);

            return $field->refresh()->load(['recordFieldOptions' => fn (Relation $options) => $options->orderBy('sort_key')->orderBy('code')]);
        });
    }

    /**
     * 消す選択肢のコードを、この項目の値に持つレコードか、この項目の条件の value に使う規則があるか。
     *
     * @param  list<string>  $codes
     */
    private function optionsInUse(RecordModule $module, RecordField $field, array $codes): bool
    {
        $key = (string) $field->id;
        $usedByRecords = Record::query()
            ->where('record_module_id', $module->id)
            ->where(function (Builder $values) use ($key, $codes) {
                foreach ($codes as $code) {
                    $values->orWhereRaw('(records.field_values ->> ?) = ?', [$key, $code]);
                }
            })
            ->exists();

        return $usedByRecords || $module->recordRules->contains(fn (RecordRule $rule) => $rule->kind === RecordRuleKind::RequiredWhen && $this->conditionUses($rule->condition ?? [], $field, $codes));
    }

    /**
     * @param  array<array-key, mixed>  $condition
     * @param  list<string>  $codes
     */
    private function conditionUses(array $condition, RecordField $field, array $codes): bool
    {
        foreach (is_array($condition['all'] ?? null) ? $condition['all'] : [] as $expression) {
            $values = is_array($expression) ? (array) ($expression['value'] ?? []) : [];

            if (is_array($expression) && ($expression['field'] ?? null) === $field->code && array_intersect($values, $codes) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{code: string, name: string}>  $options
     * @param  list<string>  $removed
     */
    private function replaceOptions(RecordField $field, array $options, array $removed): void
    {
        if ($field->type !== RecordFieldType::Choice) {
            return;
        }

        $field->recordFieldOptions()->whereIn('code', $removed)->delete();

        foreach ($options as $index => $option) {
            $current = $field->recordFieldOptions->first(fn (RecordFieldOption $existing) => $existing->code === $option['code']);
            $current === null
                ? $field->recordFieldOptions()->create([...$option, 'sort_key' => $index])
                : $current->update(['name' => $option['name'], 'sort_key' => $index]);
        }
    }
}
