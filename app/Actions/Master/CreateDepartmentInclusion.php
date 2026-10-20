<?php

namespace App\Actions\Master;

use App\Models\Master\Department;
use App\Models\Master\DepartmentInclusion;
use App\Queries\Master\EffectivePeriodQuery;
use App\Queries\Master\InclusionCycleQuery;
use App\Support\Period;
use Illuminate\Validation\ValidationException;

/**
 * 内包(組織の 1 段の親子)を作る(docs の 設計/共通マスタ/組織とユーザー.md 5 節)。作る行は有効(is_deleted = false)。
 *
 * 止める規則: 頂点の組織は子にならない / 内包が d に有効なら親子の両方の組織も d に有効(共通の規則 5.5)/
 * どの日にも、その日に有効な内包で循環を作らない。親子が同じ組織セット・自分を親にしない・子の親は同時に 1 つは DB が止める。
 */
class CreateDepartmentInclusion
{
    use WritesCommonMaster;

    public function __construct(
        private EffectivePeriodQuery $effectivePeriods,
        private InclusionCycleQuery $inclusionCycles,
    ) {}

    /**
     * @throws ValidationException 規則に反するとき
     */
    public function execute(int $parentDepartmentId, int $departmentId, Period $period, int $sortKey = 0): DepartmentInclusion
    {
        return $this->writeCommonMaster(function () use ($parentDepartmentId, $departmentId, $period, $sortKey) {
            $child = Department::query()->findOrFail($departmentId);
            $this->ensureRules($parentDepartmentId, $child, $period);

            return DepartmentInclusion::query()->create([
                'department_set_id' => $child->department_set_id,
                'parent_department_id' => $parentDepartmentId,
                'department_id' => $departmentId,
                'start_date' => $period->start->toDateString(),
                'end_date' => $period->end?->toDateString(),
                'is_deleted' => false,
                'sort_key' => $sortKey,
            ]);
        });
    }

    /**
     * @throws ValidationException 規則に反するとき
     */
    private function ensureRules(int $parentDepartmentId, Department $child, Period $period): void
    {
        if ($child->is_root) {
            throw ValidationException::withMessages(['department_id' => ['頂点の組織は子にできません']]);
        }

        if (! Period::covers($this->effectivePeriods->ofDepartment($parentDepartmentId), $period)) {
            throw ValidationException::withMessages(['parent_department_id' => ['親の組織が内包の期間の全体で有効ではありません']]);
        }

        if (! Period::covers($this->effectivePeriods->ofDepartment($child->id), $period)) {
            throw ValidationException::withMessages(['department_id' => ['子の組織が内包の期間の全体で有効ではありません']]);
        }

        if ($this->inclusionCycles->wouldCycle($parentDepartmentId, $child->id, $period)) {
            throw ValidationException::withMessages(['parent_department_id' => ['この親子にすると、組織の階層が循環します']]);
        }
    }
}
