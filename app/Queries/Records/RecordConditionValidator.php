<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordConditionOperator;
use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use Illuminate\Validation\ValidationException;

/**
 * 条件の JSON(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 8.2)の検証と値の正規化の 1 か所。規則の定義
 * (App\Actions\Records\RuleDefinitionValidator)と公開の口 {@see Api\RecordConditions::validate()} が同じこのクラスを呼ぶ
 * (検証を 2 か所に書かない = docs の 設計/ワークフロー/ルートの深さ/段2の設計への反映.md 3 節)。
 *
 * 条件 = {"all": [式 1〜10 個]}。式 = field・op・value(ほかのキーは拒む)。項目は同じ業務モジュールの明細でない項目、演算子は型ごとの可否(8.2 の表)、
 * value は 2 節の検証で正規化し、value を持つ演算子で空なら拒む。誤りのキーは condition・condition.all.<添字>(.field・.op・.value)。
 */
class RecordConditionValidator
{
    /** 条件の式の数の上限 */
    public const MAX_EXPRESSIONS = 10;

    public function __construct(private FieldValueParser $parser) {}

    /**
     * 条件の JSON を確かめて、value を正規化したものを返す。
     *
     * @param  RecordModule  $module  定義を読み込んだ業務モジュール({@see RecordDefinitionQuery::load()})
     * @param  mixed  $condition  前後の空白を除き、空文字を null にした入力
     * @return array{all: list<array<string, mixed>>}
     *
     * @throws ValidationException 規則に反するとき
     */
    public function validate(RecordModule $module, mixed $condition): array
    {
        $expressions = $this->expressionsOf($condition);
        self::throwIfAny(['condition' => $expressions === null ? '条件は {"all": [式, …]} の形で、式を 1〜'.self::MAX_EXPRESSIONS.' 個指定してください' : null]);
        $normalized = [];
        $errors = [];

        foreach ($expressions ?? [] as $index => $expression) {
            $normalized[] = $this->expression($module, $expression, "condition.all.{$index}", $errors);
        }

        self::throwIfAny($errors);

        return ['all' => $normalized];
    }

    /**
     * 条件の JSON の形だけが合うか(業務モジュールの定義に照らさない)。外側の形は {@see self::expressionsOf()} と同じ式で、各式は配列でキーが field・op・value の中、
     * field・op が文字列。{@see self::validate()} が返した条件は常に真(validate の誤りのキーと文言はこの判定と別)。
     */
    public function isWellFormed(mixed $condition): bool
    {
        $expressions = $this->expressionsOf($condition);

        return $expressions !== null && array_filter($expressions, fn (mixed $expression) => ! $this->isWellFormedExpression($expression)) === [];
    }

    private function isWellFormedExpression(mixed $expression): bool
    {
        return is_array($expression) && array_diff(array_keys($expression), ['field', 'op', 'value']) === []
            && is_string($expression['field'] ?? null) && is_string($expression['op'] ?? null);
    }

    /**
     * 条件の式の配列({"all": [...]} の形で 1〜10 個。形が違えば null)。
     *
     * @return list<mixed>|null
     */
    private function expressionsOf(mixed $condition): ?array
    {
        $expressions = is_array($condition) && array_keys($condition) === ['all'] ? $condition['all'] : null;

        return is_array($expressions) && array_is_list($expressions) && $expressions !== [] && count($expressions) <= self::MAX_EXPRESSIONS ? $expressions : null;
    }

    /**
     * 式 1 つ(誤りは $errors に足す)。
     *
     * @param  array<string, string|null>  $errors
     * @return array<string, mixed>
     */
    private function expression(RecordModule $module, mixed $expression, string $key, array &$errors): array
    {
        $parts = is_array($expression) ? $expression : [];
        $field = $this->field($module, $parts['field'] ?? null);
        $operator = is_string($parts['op'] ?? null) ? RecordConditionOperator::tryFrom($parts['op']) : null;
        $errors[$key] = is_array($expression) && array_diff(array_keys($expression), ['field', 'op', 'value']) === [] ? null : '式に指定できるのは field・op・value だけです';

        if (! $this->isUsable($field, $operator, $key, $errors)) {
            return [];
        }

        $value = $this->value($field, $operator, $parts['value'] ?? null, "{$key}.value", $errors);

        return $operator->hasValue() ? ['field' => $field->code, 'op' => $operator->value, 'value' => $value] : ['field' => $field->code, 'op' => $operator->value];
    }

    /**
     * 式の項目と演算子が使えるか(誤りは $errors に足す)。
     *
     * @param  array<string, string|null>  $errors
     *
     * @phpstan-assert-if-true !null $field
     * @phpstan-assert-if-true !null $operator
     */
    private function isUsable(?RecordField $field, ?RecordConditionOperator $operator, string $key, array &$errors): bool
    {
        $errors["{$key}.field"] = $field === null || $field->type === RecordFieldType::Details ? '条件の項目を選択してください(明細の項目は使用できません)' : null;
        $errors["{$key}.op"] = $operator === null || ($errors["{$key}.field"] === null && $field !== null && ! $operator->allows($field->type)) ? 'この項目で使用できる演算子を選択してください' : null;

        return $field !== null && $operator !== null && $errors["{$key}.field"] === null && $errors["{$key}.op"] === null;
    }

    /**
     * 式の value を正規化する(value を持つ演算子で空なら誤り。empty・not_empty は持てない)。
     *
     * @param  array<string, string|null>  $errors
     */
    private function value(RecordField $field, RecordConditionOperator $operator, mixed $value, string $key, array &$errors): mixed
    {
        try {
            $normalized = match (true) {
                ! $operator->hasValue() => $value === null ? null : throw new InvalidFieldValue('この演算子には値を設定できません'),
                $value === null || $value === [] => throw new InvalidFieldValue('値を入力してください'),
                $operator === RecordConditionOperator::In => is_array($value) && array_is_list($value) ? array_map(fn (mixed $code) => $this->parser->parse($field, $code) ?? throw new InvalidFieldValue('値を入力してください'), $value) : throw new InvalidFieldValue('値は選択肢のコードの配列で指定してください'),
                default => $this->parser->parse($field, $value),
            };
        } catch (InvalidFieldValue $exception) {
            $errors[$key] = $exception->getMessage();

            return null;
        }

        return $normalized;
    }

    /**
     * 業務モジュールの項目(コードで引く。無ければ null)。規則の定義の対象・相手の項目も同じこの引き方を使う。
     */
    public function field(RecordModule $module, mixed $code): ?RecordField
    {
        return is_string($code) ? $module->recordFields->first(fn (RecordField $field) => $field->code === $code) : null;
    }

    /**
     * 誤りがあれば、項目つきの検証の失敗にして投げる(業務データの入れ物の定義の業務処理の誤りの形の 1 か所。ValidatesDefinitionText も同じこれを呼ぶ)。
     *
     * @param  array<string, string|null>  $errors  キー → 文言(null は誤りなし)
     *
     * @throws ValidationException 誤りがあるとき
     */
    public static function throwIfAny(array $errors): void
    {
        $errors = array_filter($errors, fn (?string $message) => $message !== null);

        if ($errors !== []) {
            throw ValidationException::withMessages(array_map(fn (string $message) => [$message], $errors));
        }
    }
}
