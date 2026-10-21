<?php

namespace App\Actions\Records;

use App\Actions\Master\WritesCommonMaster;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordRuleKind;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldOption;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use App\Models\Records\RecordRule;
use App\Queries\Records\RecordDefinitionQuery;
use App\Queries\Records\RecordHoldCatalog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * 項目を消す(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/失敗と定義の変更.md 11 節)。使う規則・持ち主のあるレコード・
 * 組み込みの行があれば拒む(規則 = 設計/業務データの入れ物/データモデル/制約の分担と検査.md 6 節の例外 2、
 * 組み込み = 設計/業務ツール/共通データ.md の「入れ物の拡張」の「組み込みの旗」)。
 * 外部キーの向きに合わせて明示した順で消し、確かめた後に持ち主が増えないよう業務モジュールの排他の勧告ロック({@see LocksRecordModule})の下で行う。
 */
class DeleteRecordField
{
    use LocksRecordModule;
    use WritesCommonMaster;

    public function __construct(
        private RecordDefinitionQuery $definitions,
        private RecordHoldCatalog $holds,
    ) {}

    /**
     * @throws ModelNotFoundException<RecordModule|RecordField> 業務モジュール・項目が無いとき
     * @throws ValidationException 組み込みのとき・規則が使っているとき・業務モジュールに持ち主のあるレコードがあるとき(キー field)
     */
    public function execute(string $moduleCode, string $fieldCode): void
    {
        $this->writeCommonMaster(function () use ($moduleCode, $fieldCode) {
            $module = $this->definitions->load($this->lockRecordModuleExclusively($moduleCode));
            $field = $module->recordFields->first(fn (RecordField $field) => $field->code === $fieldCode) ?? throw (new ModelNotFoundException)->setModel(RecordField::class, [$fieldCode]);

            if ($this->containsBuiltin($field)) {
                throw ValidationException::withMessages(['field' => ['組み込みの項目は削除できません']]);
            }

            if ($module->recordRules->contains(fn (RecordRule $rule) => $this->ruleUses($rule, $field))) {
                throw ValidationException::withMessages(['field' => ["{$field->name}を使用する規則があります。先に規則を削除してください"]]);
            }

            $hold = $this->holds->firstHoldInModule($module->parent_record_module_id ?? $module->id);

            if ($hold !== null) {
                throw ValidationException::withMessages(['field' => [$hold->reason]]);
            }

            $child = $field->childRecordModule;

            if ($field->type === RecordFieldType::Details && $child !== null) {
                Record::query()->where('record_module_id', $child->id)->delete();
                RecordRule::query()->where('record_module_id', $child->id)->delete();
                RecordField::query()->where('record_module_id', $child->id)->delete();
                RecordField::query()->whereKey($field->id)->delete();
                RecordModule::query()->whereKey($child->id)->delete();

                return;
            }

            RecordReference::query()->where('record_field_id', $field->id)->delete();
            RecordField::query()->whereKey($field->id)->delete();
        });
    }

    /**
     * 項目か、項目と一緒に消える行(選択肢、明細のモジュールとその項目・選択肢)のどれかが組み込みか。
     * 明細は 1 段だけなので、明細のモジュールの項目で止まる。
     */
    private function containsBuiltin(RecordField $field): bool
    {
        $child = $field->childRecordModule;
        $isBuiltin = fn (RecordFieldOption|RecordModule $row): bool => $row->is_builtin;

        return $field->is_builtin
            || $field->recordFieldOptions->contains($isBuiltin)
            || ($child !== null && ($isBuiltin($child) || $child->recordFields->contains($this->containsBuiltin(...))));
    }

    private function ruleUses(RecordRule $rule, RecordField $field): bool
    {
        if (in_array($field->id, [$rule->target_record_field_id, $rule->compared_record_field_id], true)) {
            return true;
        }

        $expressions = $rule->kind === RecordRuleKind::RequiredWhen && is_array($rule->condition['all'] ?? null) ? $rule->condition['all'] : [];

        return array_any($expressions, fn (mixed $expression) => is_array($expression) && ($expression['field'] ?? null) === $field->code);
    }
}
