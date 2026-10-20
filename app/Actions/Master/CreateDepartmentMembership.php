<?php

namespace App\Actions\Master;

use App\Models\Master\Department;
use App\Models\Master\DepartmentMembership;
use App\Queries\Master\EffectivePeriodQuery;
use App\Queries\Master\MembershipQuery;
use App\Support\Period;
use Illuminate\Validation\ValidationException;

/**
 * 組織所属を作る(docs の 設計/共通マスタ/組織とユーザー.md 8 節)。作る行は有効(is_deleted = false)。
 *
 * 止める規則: 所属が d に有効なら組織とユーザーも d に有効(共通の規則 5.5)/ d に有効な所属があるなら d に有効な主所属が 1 つある
 * (主所属でない所属を作るなら、その期間の全体に主所属がある。数える範囲は暫定で所属の組織セットの中 = 設計/共通マスタ/組織とユーザー.md 8 節)。
 * 同じ組織への所属の重なり・主所属が同時に 2 つは DB が止める。
 */
class CreateDepartmentMembership
{
    use WritesCommonMaster;

    public function __construct(
        private EffectivePeriodQuery $effectivePeriods,
        private MembershipQuery $memberships,
    ) {}

    /**
     * @throws ValidationException 規則に反するとき
     */
    public function execute(int $userId, int $departmentId, Period $period, bool $isMain = false, int $sortKey = 0): DepartmentMembership
    {
        return $this->writeCommonMaster(function () use ($userId, $departmentId, $period, $isMain, $sortKey) {
            $department = Department::query()->findOrFail($departmentId);
            $this->ensureRules($userId, $department, $period, $isMain);

            return DepartmentMembership::query()->create([
                'department_set_id' => $department->department_set_id,
                'department_id' => $department->id,
                'user_id' => $userId,
                'start_date' => $period->start->toDateString(),
                'end_date' => $period->end?->toDateString(),
                'is_deleted' => false,
                'is_main' => $isMain,
                'sort_key' => $sortKey,
            ]);
        });
    }

    /**
     * @throws ValidationException 規則に反するとき
     */
    private function ensureRules(int $userId, Department $department, Period $period, bool $isMain): void
    {
        if (! Period::covers($this->effectivePeriods->ofDepartment($department->id), $period)) {
            throw ValidationException::withMessages(['department_id' => ['組織が所属の期間の全体で有効ではありません']]);
        }

        if (! Period::covers($this->effectivePeriods->ofUser($userId), $period)) {
            throw ValidationException::withMessages(['user_id' => ['ユーザーが所属の期間の全体で有効ではありません']]);
        }

        if (! $isMain && ! Period::covers($this->memberships->mainMembershipPeriods($userId, $department->department_set_id), $period)) {
            throw ValidationException::withMessages(['is_main' => ['所属の期間に、主所属の無い日があります(先に主所属を登録してください)']]);
        }
    }
}
