<?php

namespace App\Enums\Authz;

/**
 * 認可の対象者の条件の比較(authz_subject_conditions.operator)。比較は組織と役職でだけ使い、ユーザー・ロール・IPv4 アドレス・認証状態・期間は Equal だけ。
 * 組織 = 下位・一致か下位・一致・一致か上位・上位、役職 = ランクが下位・同じか下位・指定の役職・同じか上位・上位。
 */
enum ConditionOperator: string
{
    case LessThan = 'lt';
    case LessThanOrEqual = 'le';
    case Equal = 'eq';
    case GreaterThanOrEqual = 'ge';
    case GreaterThan = 'gt';
}
