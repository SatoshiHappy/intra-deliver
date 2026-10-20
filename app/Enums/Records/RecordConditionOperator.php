<?php

namespace App\Enums\Records;

/**
 * 条件つき必須の条件の JSON の式の演算子 op(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 8.2)。型ごとの可否と空のときの意味は 8.2 の表。
 * 相関チェックの比べ方は {@see RecordComparisonOperator}(in・empty・not_empty を持たない)。
 */
enum RecordConditionOperator: string
{
    /** 等しい */
    case Eq = 'eq';

    /** 等しくない */
    case Ne = 'ne';

    /** より小さい */
    case Lt = 'lt';

    /** 以下 */
    case Le = 'le';

    /** 以上 */
    case Ge = 'ge';

    /** より大きい */
    case Gt = 'gt';

    /** どれかに等しい(value は選択肢のコードの配列) */
    case In = 'in';

    /** 空(value を持たない) */
    case Empty = 'empty';

    /** 空でない(value を持たない) */
    case NotEmpty = 'not_empty';

    /**
     * この演算子を、その型の項目の式に使えるか(項目と規則/規則と業務処理/README.md 8.2 の表)。
     */
    public function allows(RecordFieldType $type): bool
    {
        return match ($this) {
            self::Eq => $type->isPlain(),
            self::Ne => $type->isPlain() && $type !== RecordFieldType::Boolean,
            self::Lt, self::Le, self::Ge, self::Gt => $type->isOrdered(),
            self::In => $type === RecordFieldType::Choice,
            self::Empty, self::NotEmpty => $type !== RecordFieldType::Boolean && $type !== RecordFieldType::Details,
        };
    }

    /**
     * 式に値 value を持つ演算子か(empty・not_empty は持たない)。
     */
    public function hasValue(): bool
    {
        return $this !== self::Empty && $this !== self::NotEmpty;
    }
}
