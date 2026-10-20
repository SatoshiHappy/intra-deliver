<?php

namespace App\Queries\Master;

use App\Models\Master\DepartmentInclusion;
use App\Support\Period;
use Carbon\CarbonImmutable;

/**
 * 内包の循環(docs の 設計/共通マスタ/組織とユーザー.md 5 節: どの日にも、その日に有効な内包で循環を作らない。
 * 日の違う内包どうしや無効の内包は循環に数えない)。
 */
class InclusionCycleQuery
{
    public function __construct(private DepartmentTreeQuery $departmentTree) {}

    /**
     * 期間に有効な内包「親 → 子」を足すと、期間のどこかの日に有効な内包で循環ができるか。
     * 循環ができる = その日に、子から有効な内包を子へたどって親に届く。
     * 有効な内包の組み合わせが変わるのは内包の行の開始日・終了日だけなので、期間の開始日と、期間の中にある有効な内包の端の日を調べる。
     */
    public function wouldCycle(int $parentDepartmentId, int $departmentId, Period $period): bool
    {
        foreach ($this->daysToCheck($period) as $day) {
            if (in_array($parentDepartmentId, $this->departmentTree->descendantIdsOverEffectiveRows($departmentId, $day), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, CarbonImmutable>
     */
    private function daysToCheck(Period $period): array
    {
        $edges = DepartmentInclusion::query()->get()
            ->map(fn (DepartmentInclusion $inclusion) => $inclusion->effectivePeriod())
            ->filter()
            ->flatMap(fn (Period $effective) => [$effective->start, $effective->end])
            ->filter(fn (?CarbonImmutable $day) => $day !== null && $period->includes($day));

        return collect([$period->start, ...$edges->all()])
            ->unique(fn (CarbonImmutable $day) => $day->toDateString())
            ->values()
            ->all();
    }
}
