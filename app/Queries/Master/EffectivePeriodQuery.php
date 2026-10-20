<?php

namespace App\Queries\Master;

use App\Models\Master\CompanyPostTerm;
use App\Models\Master\DepartmentTerm;
use App\Models\Master\UserTerm;
use App\Support\Period;

/**
 * 期間化するマスタ(組織・役職・ユーザー)が有効な日の全体 = 有効な期間の行の期間の和(docs の 設計/共通マスタ/共通の規則.md 5.4)。
 *
 * 業務処理が「関係が d に有効なら、指すマスタも d に有効」(共通の規則 5.5)を確かめるのに使う。
 * 行の有効な期間は HasPeriod::effectivePeriod(無効の行は数えない)。
 */
class EffectivePeriodQuery
{
    /**
     * @return list<Period>
     */
    public function ofDepartment(int $departmentId): array
    {
        return Period::union(DepartmentTerm::query()->where('department_id', $departmentId)->get()
            ->map(fn (DepartmentTerm $term) => $term->effectivePeriod())->filter()->all());
    }

    /**
     * @return list<Period>
     */
    public function ofCompanyPost(int $companyPostId): array
    {
        return Period::union(CompanyPostTerm::query()->where('company_post_id', $companyPostId)->get()
            ->map(fn (CompanyPostTerm $term) => $term->effectivePeriod())->filter()->all());
    }

    /**
     * @return list<Period>
     */
    public function ofUser(int $userId): array
    {
        return Period::union(UserTerm::query()->where('user_id', $userId)->get()
            ->map(fn (UserTerm $term) => $term->effectivePeriod())->filter()->all());
    }
}
