<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordConditionOperator;
use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldOption;
use App\Models\Records\RecordModule;
use App\Support\AccessContext;
use DateTimeZone;
use LogicException;

/**
 * 条件の JSON(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 8.2)の人が読める文(例「予算(万円) < 300」「状態 が 完了」)。
 * 規則の画面の要約の条件の部分(TS の expressionText)と同じ文を作る。2 か所で作るので、同じ入力の組を両方のテストに置いてずれを止める。
 * 値を名前に引き直すのは選択肢だけ(参照の項目は値を持たない演算子だけを使う)。日付・日時は docs の 設計/業務データの入れ物/画面/README.md の 1.3 節の表示の形。
 *
 * @see resources/js/lib/Records/recordDefinitions.ts の expressionText・ruleSummary
 * @see tests/Feature/Queries/Records/RecordConditionsDescribeTest.php
 */
final class RecordConditionText
{
    /** 比べる演算子の記号(TS の COMPARISON_OPERATORS の写し。eq・ne は語で書く) */
    private const SYMBOLS = ['lt' => '<', 'le' => '≦', 'ge' => '≧', 'gt' => '>'];

    /** 日時として読む形(時差つき。読めなければ値のまま出す = TS の formatDatetime と同じ) */
    private const DATETIME_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})$/D';

    public function __construct(private RecordConditionValidator $validator) {}

    /**
     * 式の文を「、」でつなぐ。項目は今の定義の名前で、今の定義に無い項目はコードのまま。
     *
     * @param  RecordModule  $module  項目と選択肢を読み込んだ業務モジュール
     * @param  array<array-key, mixed>  $condition  形の合う条件({@see RecordConditionValidator::isWellFormed()})
     *
     * @throws LogicException 形の合わない条件・無い演算子(呼び手の誤り。書く条件は検証済み)
     */
    public function of(RecordModule $module, array $condition): string
    {
        if (! $this->validator->isWellFormed($condition)) {
            throw new LogicException('形の合わない条件は文にしない');
        }

        /** @var list<array{field: string, op: string, value?: mixed}> $expressions */
        $expressions = $condition['all'];

        return implode('、', array_map(fn (array $expression) => $this->expression($module, $expression), $expressions));
    }

    /**
     * @param  array{field: string, op: string, value?: mixed}  $expression
     */
    private function expression(RecordModule $module, array $expression): string
    {
        $field = $this->validator->field($module, $expression['field']);
        $name = $field === null ? $expression['field'] : $field->name;
        $value = $this->valueText($field, $expression['value'] ?? null);
        $operator = RecordConditionOperator::tryFrom($expression['op'])
            ?? throw new LogicException("無い演算子は文にしない: {$expression['op']}");

        return match ($operator) {
            RecordConditionOperator::Eq => "{$name} が {$value}",
            RecordConditionOperator::Ne => "{$name} が {$value} 以外",
            RecordConditionOperator::In => "{$name} が {$value} のどれか",
            RecordConditionOperator::Empty => "{$name} が空",
            RecordConditionOperator::NotEmpty => "{$name} が空でない",
            default => "{$name} ".self::SYMBOLS[$operator->value]." {$value}",
        };
    }

    private function valueText(?RecordField $field, mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return is_array($value)
            ? implode('・', array_map(fn (mixed $item) => $this->itemText($field, $item), $value))
            : $this->itemText($field, $value);
    }

    /**
     * 値 1 つ(選択は名称、真偽は「はい」「いいえ」、日付は YYYY/MM/DD、日時は Asia/Tokyo の YYYY/MM/DD HH:MM)。
     */
    private function itemText(?RecordField $field, mixed $item): string
    {
        if (is_bool($item)) {
            return $item ? 'はい' : 'いいえ';
        }

        $text = is_scalar($item) ? (string) $item : (string) json_encode($item);

        if ($field === null) {
            return $text;
        }

        return match ($field->type) {
            RecordFieldType::Choice => $field->recordFieldOptions
                ->first(fn (RecordFieldOption $option) => $option->code === $text)->name ?? $text,
            RecordFieldType::Date => preg_replace('/^(\d{4})-(\d{2})-(\d{2})$/D', '$1/$2/$3', $text) ?? $text,
            RecordFieldType::Datetime => self::datetimeText($text),
            default => $text,
        };
    }

    private static function datetimeText(string $text): string
    {
        $datetime = preg_match(self::DATETIME_PATTERN, $text) === 1 ? date_create_immutable($text) : false;

        return $datetime === false
            ? $text
            : $datetime->setTimezone(new DateTimeZone(AccessContext::TIMEZONE))->format('Y/m/d H:i');
    }
}
