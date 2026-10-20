<?php

namespace App\Queries\Master;

use App\Models\Master\DepartmentMembership;
use App\Models\Master\DepartmentTerm;
use DateTimeInterface;

/**
 * ユーザーの主所属(docs の 設計/共通マスタ/検索と組織図.md 2 節の「ユーザーの主所属」): d に成り立つ所属のうち、表示する組織セットにあり
 * 主所属のもので、その組織が組織図の木({@see OrganizationChartQuery::treeDepartmentsOn()})にあるもの。名称は d・ロケールの値。
 * 検索の候補({@see MasterSearchQuery})と表示名の公開の口(Api\MasterNames)が同じ規則で引く 1 か所。
 *
 * @phpstan-import-type NamedDepartment from OrganizationChartQuery
 */
class MainDepartmentQuery
{
    public function __construct(
        private OrganizationChartQuery $chart,
        private MembershipQuery $memberships,
        private TermQuery $terms,
    ) {}

    /**
     * @param  list<int>  $userIds
     * @return array<int, NamedDepartment> ユーザーの id → 主所属の組織(名称が無ければ null)。主所属の無いユーザーは入らない
     */
    public function mainDepartmentsOn(array $userIds, DateTimeInterface $date, string $localeCode): array
    {
        if ($userIds === []) {
            return [];
        }

        $treeIds = array_values($this->chart->treeDepartmentsOn($date)->modelKeys());
        $memberships = $this->memberships->mainMembershipsAmongOn($userIds, $treeIds, $date);
        $departmentIds = $memberships->map(fn (DepartmentMembership $membership) => $membership->department_id)->all();
        $names = $this->terms->departmentTermsOn(array_values(array_unique($departmentIds)), $date, $localeCode)
            ->map(fn (DepartmentTerm $term) => $term->departmentTermTranslations->first()?->name)
            ->all();
        $main = [];

        foreach ($memberships as $membership) {
            $department = $membership->department;

            if ($department !== null) {
                $name = $names[$department->id] ?? null;
                $main[$membership->user_id] = ['id' => $department->id, 'code' => $department->code, 'name' => $name];
            }
        }

        return $main;
    }
}
