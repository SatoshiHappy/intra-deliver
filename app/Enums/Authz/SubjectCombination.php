<?php

namespace App\Enums\Authz;

/**
 * 認可の対象者の条件の組み方(authz_subject_groups.combination)。All = すべての条件に合う、Any = いずれかに合う。
 */
enum SubjectCombination: string
{
    case All = 'all';
    case Any = 'any';
}
