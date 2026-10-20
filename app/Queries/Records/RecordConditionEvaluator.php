<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordComparisonOperator;
use App\Enums\Records\RecordConditionOperator;
use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordField;
use Illuminate\Database\Eloquent\Collection;

/**
 * 規則の条件(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 8.2 の {"all": [式, …]})の評価と、2 つの値の比べ方の 1 か所。
 * 条件つき必須(業務処理の App\Actions\Records\RuleEvaluator)と公開の口 {@see Api\RecordConditions::matches()} が同じこのクラスを呼ぶ
 * (判定を 2 か所に書かない = docs の 設計/ワークフロー/ルートの深さ/段2の設計への反映.md 3 節)。相関チェックの比べ方も {@see self::satisfies()} を使う。
 *
 * 式ごとの 1 判定(同 3 節の matches の行、定義の表.md 2.4、C-R11 の決定): 式の構造か写した値が今の定義に合わない式は「項目が空」として評価し
 * (empty だけが真)、合う式は写した値のまま比べる。評価で見るのは値の型の形だけで、正規化・長さ・選択肢の検証はしない(書く時の検証だけが行う)。
 */
class RecordConditionEvaluator
{
    public function __construct(private FieldValueParser $parser) {}

    /**
     * 条件が、値で真か(式がすべて真なら真)。
     *
     * @param  Collection<int, RecordField>  $fields  業務モジュールの今の項目(選択の項目は選択肢を読み込んでおく)
     * @param  array<string, mixed>  $values  明細を除く項目のコード → 値(空は null、真偽は bool、参照は相手の id、
     *                                        複数選べる参照は id の配列。空の配列は null に揃えて渡す)
     * @param  array<array-key, mixed>|null  $condition  条件の JSON(規則・分岐の辺が持つ写し)。null は条件なし(真。段 1 の規則の評価の今までの扱い)。
     *                                                   null でなく {"all": 空でないリスト} の形でない写しは偽(壊れた写しで分岐の枝へ進まない閉じる向き)
     */
    public function holds(Collection $fields, array $values, ?array $condition): bool
    {
        if ($condition === null) {
            return true;
        }

        $expressions = $condition['all'] ?? null;

        if (array_keys($condition) !== ['all'] || ! is_array($expressions) || ! array_is_list($expressions) || $expressions === []) {
            return false;
        }

        foreach ($expressions as $expression) {
            if (! $this->expressionHolds($fields, $values, is_array($expression) ? $expression : [])) {
                return false;
            }
        }

        return true;
    }

    /**
     * 「左 比べ方 右」が真か(どちらも空でない、同じ型の正規の形の値)。
     */
    public function satisfies(RecordFieldType $type, RecordComparisonOperator $operator, mixed $left, mixed $right): bool
    {
        $order = $this->order($type, $left, $right);

        return match ($operator) {
            RecordComparisonOperator::Lt => $order < 0,
            RecordComparisonOperator::Le => $order <= 0,
            RecordComparisonOperator::Eq => $order === 0,
            RecordComparisonOperator::Ne => $order !== 0,
            RecordComparisonOperator::Ge => $order >= 0,
            RecordComparisonOperator::Gt => $order > 0,
        };
    }

    /**
     * 式 1 つの真偽。今の定義に合わない式は、項目の値を空(null)として評価する(8.2 の空のときの意味 = empty だけが真)。
     * 演算子が分からない式は偽(どの意味でも評価できない)。
     *
     * @param  Collection<int, RecordField>  $fields
     * @param  array<string, mixed>  $values
     * @param  array<array-key, mixed>  $expression
     */
    private function expressionHolds(Collection $fields, array $values, array $expression): bool
    {
        $operator = is_string($expression['op'] ?? null) ? RecordConditionOperator::tryFrom($expression['op']) : null;
        $field = $fields->first(fn (RecordField $field) => $field->code === ($expression['field'] ?? null));
        $expected = $expression['value'] ?? null;

        if ($operator === null) {
            return false;
        }

        if ($field === null || ! $this->fits($field, $operator, $expected)) {
            return $operator === RecordConditionOperator::Empty;
        }

        $actual = $values[$field->code] ?? null;

        return match ($operator) {
            RecordConditionOperator::Empty => $actual === null,
            RecordConditionOperator::NotEmpty => $actual !== null,
            RecordConditionOperator::In => $actual !== null && is_array($expected) && in_array($actual, $expected, true),
            default => $actual !== null && $expected !== null && $this->satisfies($field->type, RecordComparisonOperator::from($operator->value), $actual, $expected),
        };
    }

    /**
     * 式が今の項目の定義に合うか: 演算子がその型に使え(明細の項目にはどの演算子も使えない = 8.2 の表)、写した値が今の型の正規の形
     * (in は要素のどれもが)であること。
     */
    private function fits(RecordField $field, RecordConditionOperator $operator, mixed $expected): bool
    {
        if (! $operator->allows($field->type)) {
            return false;
        }

        return match (true) {
            ! $operator->hasValue() => true,
            $operator === RecordConditionOperator::In => is_array($expected) && array_is_list($expected) && $expected !== [] && array_all($expected, fn (mixed $code) => $this->isCanonical($field, $code)),
            default => $this->isCanonical($field, $expected),
        };
    }

    /**
     * 写した値が今の項目の型の正規の形か = 項目の値の読み方({@see FieldValueParser::parse()})が同じ値をそのまま返す形。長さの超過・消えた選択肢
     * (値の形には合う誤り = {@see InvalidFieldValue::$isWrongShape} が偽)は形が合うとみなす(写した値のまま比べる)。値を正規化して比べない。
     */
    private function isCanonical(RecordField $field, mixed $value): bool
    {
        try {
            return $this->parser->parse($field, $value) === $value;
        } catch (InvalidFieldValue $exception) {
            return ! $exception->isWrongShape;
        }
    }

    /**
     * 2 つの値の順(負・0・正)。整数は数、小数は numeric と同じ精度、日付・日時は文字列の順(正規の形なので時刻の順)、
     * 文字列・選択・真偽は一致だけに使う(完全一致なら 0)。
     */
    private function order(RecordFieldType $type, mixed $left, mixed $right): int
    {
        return match (true) {
            $type === RecordFieldType::Integer => $left <=> $right,
            $type === RecordFieldType::Decimal && is_string($left) && is_string($right) => $this->decimalOrder($left, $right),
            is_string($left) && is_string($right) => strcmp($left, $right) <=> 0,
            default => $left === $right ? 0 : 1,
        };
    }

    /**
     * 正規の形の小数の文字列(設計/業務データの入れ物/データモデル/値の形と直列化.md 3 節)の順。
     */
    private function decimalOrder(string $left, string $right): int
    {
        $leftNegative = str_starts_with($left, '-');
        $rightNegative = str_starts_with($right, '-');

        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }

        $order = strcmp($this->padded(ltrim($left, '-')), $this->padded(ltrim($right, '-'))) <=> 0;

        return $leftNegative ? -$order : $order;
    }

    /**
     * 小数の絶対値を、整数部と小数部の桁を揃えた文字列にする(文字列の順が数の順になる)。
     */
    private function padded(string $absolute): string
    {
        [$integer, $fraction] = array_pad(explode('.', $absolute, 2), 2, '');

        return str_pad($integer, FieldValueParser::INTEGER_DIGITS, '0', STR_PAD_LEFT).'.'.str_pad($fraction, FieldValueParser::FRACTION_DIGITS, '0');
    }
}
