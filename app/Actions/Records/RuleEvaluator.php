<?php

namespace App\Actions\Records;

use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordRuleKind;
use App\Models\Records\RecordField;
use App\Models\Records\RecordRule;
use App\Queries\Records\RecordConditionEvaluator;

/**
 * 必須(項目の属性)・条件つき必須・相関チェックの評価(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 8 節、9.2 の 7・8、文言は 失敗と定義の変更.md 10 節)。
 * 合わせた値(9.2 の 5)を {@see RecordValidator::validate()} が parse した後に評価する。空 = 値が null(明細は 0 行)。parse の後は真偽が
 * いつも bool なので空にならず、キーの無い真偽も偽として比べる(特別扱いを置かない)。条件の評価と値の比べ方は {@see RecordConditionEvaluator}
 * (分岐の条件の公開の口と同じ 1 か所。式の構造か写した値が今の定義に合わない式は空として評価する)。
 */
class RuleEvaluator
{
    /** 相関チェックの既定の文言の語(10 節) */
    private const COMPARISON_WORDS = [
        'lt' => 'より小さい値',
        'le' => '以下',
        'eq' => 'と同じ値',
        'ne' => 'と違う値',
        'ge' => '以上',
        'gt' => 'より大きい値',
    ];

    public function __construct(private RecordConditionEvaluator $conditions) {}

    /**
     * 1 つのレコードの必須と規則の失敗を $errors に足す(キー → 最初の文言)。型の形に合わない値の項目($wrongShapeCodes)を対象・相手・条件に
     * 使う規則は評価しない(その項目の誤りは 9.2 の 6 で返っている = M01)。
     *
     * @param  array<string, int>  $detailCounts  明細の項目のコード → 保存後に残る行の数
     * @param  array<string, string>  $errors
     * @param  list<string>  $wrongShapeCodes  型の形に合わない値の項目のコード
     */
    public function check(RecordDraft $draft, array $detailCounts, array &$errors, array $wrongShapeCodes): void
    {
        foreach ($draft->module->recordFields as $field) {
            if ($field->is_required && $this->isEmpty($draft, $field, $detailCounts)) {
                $errors[$this->keyOf($draft, $field)] ??= $this->requiredMessage($field);
            }
        }

        foreach ($draft->module->recordRules as $rule) {
            if ($wrongShapeCodes === [] || array_intersect($this->fieldCodesUsedBy($draft, $rule), $wrongShapeCodes) === []) {
                $this->checkRule($draft, $rule, $detailCounts, $errors);
            }
        }
    }

    /**
     * 規則が対象・相手・条件に使う項目のコード。
     *
     * @return list<string|null>
     */
    private function fieldCodesUsedBy(RecordDraft $draft, RecordRule $rule): array
    {
        $conditionCodes = array_map(fn (mixed $expression) => is_array($expression) && is_string($expression['field'] ?? null) ? $expression['field'] : null, $rule->condition['all'] ?? []);

        return [
            $this->fieldById($draft, $rule->target_record_field_id)?->code,
            $this->fieldById($draft, $rule->compared_record_field_id)?->code,
            ...array_values($conditionCodes),
        ];
    }

    /**
     * @param  array<string, int>  $detailCounts
     * @param  array<string, string>  $errors
     */
    private function checkRule(RecordDraft $draft, RecordRule $rule, array $detailCounts, array &$errors): void
    {
        $target = $this->fieldById($draft, $rule->target_record_field_id);
        $message = $target === null ? null : $this->ruleFailure($draft, $rule, $target, $detailCounts);

        if ($target !== null && $message !== null) {
            $errors[$this->keyOf($draft, $target)] ??= $message;
        }
    }

    /**
     * 規則の失敗の文言(通れば null)。
     *
     * @param  array<string, int>  $detailCounts
     */
    private function ruleFailure(RecordDraft $draft, RecordRule $rule, RecordField $target, array $detailCounts): ?string
    {
        if ($rule->kind === RecordRuleKind::RequiredWhen) {
            $fails = $this->conditions->holds($draft->module->recordFields, $draft->values, $rule->condition) && $this->isEmpty($draft, $target, $detailCounts);

            return $fails ? $rule->message ?? $this->requiredMessage($target) : null;
        }

        $compared = $this->fieldById($draft, $rule->compared_record_field_id);
        $left = $draft->values[$target->code] ?? null;
        $right = $compared === null ? null : $draft->values[$compared->code] ?? null;

        if ($compared === null || $rule->operator === null || $left === null || $right === null || $this->conditions->satisfies($target->type, $rule->operator, $left, $right)) {
            return null;
        }

        return $rule->message ?? "{$target->name}は{$compared->name}".self::COMPARISON_WORDS[$rule->operator->value].'にしてください';
    }

    /**
     * @param  array<string, int>  $detailCounts
     */
    private function isEmpty(RecordDraft $draft, RecordField $field, array $detailCounts): bool
    {
        return match ($field->type) {
            RecordFieldType::Details => ($detailCounts[$field->code] ?? 0) === 0,
            default => ($draft->values[$field->code] ?? null) === null,
        };
    }

    private function keyOf(RecordDraft $draft, RecordField $field): string
    {
        return $field->type === RecordFieldType::Details ? $draft->keyPrefix.'details.'.$field->code : $draft->key($field->code);
    }

    private function requiredMessage(RecordField $field): string
    {
        return $field->type === RecordFieldType::Details ? "{$field->name}を 1 行以上入力してください" : "{$field->name}を入力してください";
    }

    private function fieldById(RecordDraft $draft, ?int $fieldId): ?RecordField
    {
        return $draft->module->recordFields->first(fn (RecordField $field) => $field->id === $fieldId);
    }
}
