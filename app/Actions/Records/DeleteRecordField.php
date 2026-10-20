<?php

namespace App\Actions\Records;

use App\Actions\Master\WritesCommonMaster;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordRuleKind;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use App\Models\Records\RecordRule;
use App\Queries\Records\RecordDefinitionQuery;
use App\Queries\Records\RecordHoldCatalog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * 項目を消す(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/失敗と定義の変更.md 11 節)。その項目を対象・相手・条件に使う規則があれば拒む(キー field。
 * 規則を先に消す = 設計/業務データの入れ物/データモデル/制約の分担と検査.md 6 節の例外 2)。その業務モジュール(明細のモジュールなら親)のレコードに持ち主があっても拒む(持ち主の規則。キー field、
 * 文言 = 持ち主の理由。業務モジュールの排他の勧告ロックの下で {@see RecordHoldCatalog::firstHoldInModule()} を呼ぶ。新しい持ち主は共有の勧告ロックの下でしか
 * できないので、確かめた後に増えない)。拒まなければ、外部キーの向きに合わせて明示した順で消す。
 * 通常・参照の項目 = 参照の値の行 → 項目(選択肢は連鎖)。field_values は書き換えない(残った id のキーは読み取りが無視する)。
 * 型「明細」の項目 = 明細の行(その参照の値の行は連鎖)→ 明細のモジュールの規則 → 明細のモジュールの項目 → この項目 → 明細のモジュール。
 * 共通マスタと業務モジュールの排他の勧告ロック({@see LocksRecordModule})の下で行う。
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
     * @throws ValidationException 規則が使っているとき・業務モジュールに持ち主のあるレコードがあるとき(キー field)
     */
    public function execute(string $moduleCode, string $fieldCode): void
    {
        $this->writeCommonMaster(function () use ($moduleCode, $fieldCode) {
            $module = $this->definitions->load($this->lockRecordModuleExclusively($moduleCode));
            $field = $module->recordFields->first(fn (RecordField $field) => $field->code === $fieldCode) ?? throw (new ModelNotFoundException)->setModel(RecordField::class, [$fieldCode]);

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

    private function ruleUses(RecordRule $rule, RecordField $field): bool
    {
        if (in_array($field->id, [$rule->target_record_field_id, $rule->compared_record_field_id], true)) {
            return true;
        }

        $expressions = $rule->kind === RecordRuleKind::RequiredWhen && is_array($rule->condition['all'] ?? null) ? $rule->condition['all'] : [];

        return array_any($expressions, fn (mixed $expression) => is_array($expression) && ($expression['field'] ?? null) === $field->code);
    }
}
