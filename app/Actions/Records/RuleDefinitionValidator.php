<?php

namespace App\Actions\Records;

use App\Enums\Records\RecordComparisonOperator;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordRuleKind;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Queries\Records\RecordConditionValidator;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 規則の定義の入力を確かめる(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 8 節、失敗と定義の変更.md 11 節、設計/業務データの入れ物/データモデル/制約の分担と検査.md
 * 6 節の例外 3)。入力 = { kind, target, condition, operator, compared, message }(画面/管理の画面.md 3.3。項目はコード。キーはエラーのキー)。
 *
 * 条件つき必須 = 対象(真偽でない項目)と条件(検証と正規化は {@see RecordConditionValidator}。分岐の条件の公開の口と同じ 1 か所)。
 * 相関チェック = 対象と相手(同じ型の整数・小数・日付・日時の別の項目)と比べ方。
 */
class RuleDefinitionValidator
{
    use ValidatesDefinitionText;

    public function __construct(private RecordConditionValidator $conditions) {}

    /**
     * @param  RecordModule  $module  定義を読み込んだ業務モジュール
     * @param  RecordRuleKind|null  $kind  変えるときの今の種別(種別は変えない。null = 作る)
     * @param  array<array-key, mixed>  $input  前後の空白を除いた入力
     * @return array{kind: RecordRuleKind, target_record_field_id: int, condition: array<string, mixed>|null, operator: RecordComparisonOperator|null, compared_record_field_id: int|null, message: string|null}
     *
     * @throws ValidationException 規則に反するとき
     */
    public function validate(RecordModule $module, ?RecordRuleKind $kind, array $input): array
    {
        $given = is_string($input['kind'] ?? null) ? RecordRuleKind::tryFrom($input['kind']) : null;
        $target = $this->conditions->field($module, $input['target'] ?? null);
        $message = $input['message'] ?? null;
        $errors = [
            'kind' => $this->kindError($kind, $given, array_key_exists('kind', $input)),
            'target' => $target === null ? '対象の項目を選択してください' : null,
            'message' => $this->textError($message, '文言', false),
        ];
        $kind ??= $given;
        $this->throwIfAny($errors);

        if ($kind === null || $target === null) {
            throw new LogicException('種別と対象の空は、直前の throwIfAny が項目つきで拒む');
        }

        $attributes = $kind === RecordRuleKind::RequiredWhen ? $this->requiredWhen($module, $target, $input) : $this->compare($module, $target, $input);

        return [...$attributes, 'kind' => $kind, 'target_record_field_id' => $target->id, 'message' => is_string($message) ? $message : null];
    }

    private function kindError(?RecordRuleKind $current, ?RecordRuleKind $given, bool $isGiven): ?string
    {
        return match (true) {
            $current === null => $given === null ? '種別を選択してください' : null,
            $isGiven && $given !== $current => '種別は変更できません(削除してから再度追加してください)',
            default => null,
        };
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array{condition: array<string, mixed>, operator: null, compared_record_field_id: null}
     *
     * @throws ValidationException 規則に反するとき
     */
    private function requiredWhen(RecordModule $module, RecordField $target, array $input): array
    {
        $this->throwIfAny([
            'target' => $target->type === RecordFieldType::Boolean ? '真偽の項目は条件つき必須の対象にできません' : null,
            'operator' => ($input['operator'] ?? null) === null ? null : '条件つき必須には比べ方を設定できません',
            'compared' => ($input['compared'] ?? null) === null ? null : '条件つき必須には相手の項目を設定できません',
        ]);

        return ['condition' => $this->conditions->validate($module, $input['condition'] ?? null), 'operator' => null, 'compared_record_field_id' => null];
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array{condition: null, operator: RecordComparisonOperator, compared_record_field_id: int}
     *
     * @throws ValidationException 規則に反するとき
     */
    private function compare(RecordModule $module, RecordField $target, array $input): array
    {
        $operator = is_string($input['operator'] ?? null) ? RecordComparisonOperator::tryFrom($input['operator']) : null;
        $compared = $this->conditions->field($module, $input['compared'] ?? null);
        $this->throwIfAny([
            'target' => $target->type->isOrdered() ? null : '相関チェックの対象は整数・小数・日付・日時の項目にしてください',
            'operator' => $operator === null ? '比べ方を選択してください' : null,
            'compared' => match (true) {
                $compared === null => '相手の項目を選択してください',
                $compared->id === $target->id => '相手の項目は対象と別の項目にしてください',
                $compared->type !== $target->type => '相手の項目は対象と同じ型の項目にしてください',
                default => null,
            },
            'condition' => ($input['condition'] ?? null) === null ? null : '相関チェックには条件を設定できません',
        ]);

        if ($operator === null || $compared === null) {
            throw new LogicException('比べ方と相手の項目の空は、直前の throwIfAny が項目つきで拒む');
        }

        return ['condition' => null, 'operator' => $operator, 'compared_record_field_id' => $compared->id];
    }
}
