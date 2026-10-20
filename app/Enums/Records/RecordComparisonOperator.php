<?php

namespace App\Enums\Records;

/**
 * 相関チェックの比べ方(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 8.1。表 record_rules の operator)。
 * 語は認可の対象者の比較(lt・le・eq・ge・gt)に揃え、ne を足した。条件の JSON の演算子は {@see RecordConditionOperator}。
 */
enum RecordComparisonOperator: string
{
    /** より小さい */
    case Lt = 'lt';

    /** 以下 */
    case Le = 'le';

    /** 等しい */
    case Eq = 'eq';

    /** 等しくない */
    case Ne = 'ne';

    /** 以上 */
    case Ge = 'ge';

    /** より大きい */
    case Gt = 'gt';
}
