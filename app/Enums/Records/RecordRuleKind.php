<?php

namespace App\Enums\Records;

/**
 * 規則の種別(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 8.1。表 record_rules の kind)。種別を足すには配備が要る。
 */
enum RecordRuleKind: string
{
    /** 条件つき必須(対象の項目・条件の JSON を持つ) */
    case RequiredWhen = 'required_when';

    /** 相関チェック(対象の項目・比べ方・相手の項目を持つ) */
    case Compare = 'compare';
}
