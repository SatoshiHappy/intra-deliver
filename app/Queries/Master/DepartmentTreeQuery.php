<?php

namespace App\Queries\Master;

use App\Models\Master\Department;
use App\Models\Master\DepartmentInclusion;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * 組織ツリーと上位・下位の組織(docs の 設計/共通マスタ/組織とユーザー.md 5 節・9 節)。
 *
 * どれも d に成り立つ内包(共通の規則 5.4 = 内包の行と親子の組織が d に有効。DepartmentInclusion の holdsOn)だけをたどる。
 * 内包は 1 段の親子だけを持つので、上位・下位は WITH RECURSIVE でその都度たどる(README の方針 6)。
 * 頂点から届かない部分木の中でもたどる(ロールと認可/対象者.md の比較の範囲)。
 * 循環は業務処理が止める(DB では止まらない)。止まらなかった循環でも終わるように、たどった組織を UNION で重ねない。
 * 循環があっても起点の組織そのものは結果に入れない(上位・下位に自分を数えない)。
 * 並びは一覧の規則(共通の規則 9 節)のソートキー、コードの順(同じなら id の順)。
 */
class DepartmentTreeQuery
{
    /**
     * 組織の上位の組織すべて(d に成り立つ内包を親へたどった組織。自分は含まない)。
     *
     * @return Collection<int, Department>
     */
    public function ancestors(int $departmentId, DateTimeInterface $date): Collection
    {
        return $this->reachable($departmentId, $date, upward: true);
    }

    /**
     * 組織の上位の組織の id を近い順に(親・親の親・… 根まで。自分は含まない。設計/共通マスタ/組織とユーザー.md 9 節「組織の上位組織すべて」)。
     * 上位の集まりは {@see self::ancestors()} と同じで、その中の d に成り立つ内包(ある日に子の親は 1 つ = 同 5 節)を親へつないで並べる。
     * 組織が d に有効でなければ、成り立つ内包が無いので空。止まらなかった循環でも、たどった組織に戻ったところで止める。
     *
     * @return list<int>
     */
    public function ancestorIdsNearestFirst(int $departmentId, DateTimeInterface $date): array
    {
        $parents = array_map('intval', DepartmentInclusion::query()
            ->holdsOn($date)
            ->whereIn('department_id', [$departmentId, ...$this->ancestors($departmentId, $date)->modelKeys()])
            ->pluck('parent_department_id', 'department_id')
            ->all());
        $ids = [];

        for ($parent = $parents[$departmentId] ?? null; $parent !== null && $parent !== $departmentId && ! in_array($parent, $ids, true); $parent = $parents[$parent] ?? null) {
            $ids[] = $parent;
        }

        return $ids;
    }

    /**
     * 組織の下位の組織すべて(d に成り立つ内包を子へたどった組織。自分は含まない)。
     *
     * @return Collection<int, Department>
     */
    public function descendants(int $departmentId, DateTimeInterface $date): Collection
    {
        return $this->reachable($departmentId, $date, upward: false);
    }

    /**
     * 組織から、d に有効な内包の行(行の有効だけを見る。指す組織の有効は見ない)を子へたどった組織の id(自分は含まない)。
     * 業務処理の規則は行の「有効」で書く(共通の規則 5.4 の最後)ので、内包の循環の検査に使う。
     *
     * @return list<int>
     */
    public function descendantIdsOverEffectiveRows(int $departmentId, DateTimeInterface $date): array
    {
        return array_values($this->reachableVia(DepartmentInclusion::query()->effectiveOn($date), $departmentId, upward: false)
            ->map(fn (Department $department) => $department->id)
            ->all());
    }

    /**
     * 組織セットの d の組織ツリー = 頂点の組織と、頂点から d に成り立つ内包を子へたどって届く組織。
     * 頂点の組織が d に有効でなければ空(その組織セットのツリーは d には無い)。
     * 各組織の inclusionsAsChild に、d に成り立つ親との内包(頂点は 0 件、ほかは 1 件。兄弟の並びの sort_key を持つ)を読み込む。
     *
     * @return Collection<int, Department>
     */
    public function tree(int $departmentSetId, DateTimeInterface $date): Collection
    {
        $root = Department::query()
            ->where('department_set_id', $departmentSetId)
            ->where('is_root', true)
            ->effectiveOn($date)
            ->first();

        if ($root === null) {
            return new Collection;
        }

        $descendantIds = $this->descendants($root->id, $date)->map(fn (Department $department) => $department->id)->all();

        return Department::query()
            ->whereKey([$root->id, ...$descendantIds])
            ->with('inclusionsAsChild', fn (Relation $inclusions) => $inclusions->holdsOn($date))
            ->orderBy('sort_key')
            ->orderBy('code')
            ->orderBy('id')
            ->get();
    }

    /**
     * 組織から、d に成り立つ内包を 1 つの向きにたどって届く組織。
     *
     * @return Collection<int, Department>
     */
    private function reachable(int $departmentId, DateTimeInterface $date, bool $upward): Collection
    {
        return $this->reachableVia(DepartmentInclusion::query()->holdsOn($date), $departmentId, $upward);
    }

    /**
     * 組織から、渡した内包の集まりを 1 つの向きにたどって届く組織。
     *
     * @param  Builder<DepartmentInclusion>  $inclusions
     * @return Collection<int, Department>
     */
    private function reachableVia(Builder $inclusions, int $departmentId, bool $upward): Collection
    {
        $holding = $inclusions->select(['parent_department_id', 'department_id']);
        [$from, $to] = $upward ? ['department_id', 'parent_department_id'] : ['parent_department_id', 'department_id'];

        $sql = sprintf(<<<'SQL'
            WITH RECURSIVE holding AS (%1$s),
            reached (id) AS (
                SELECT holding.%3$s FROM holding WHERE holding.%2$s = ?
                UNION
                SELECT holding.%3$s FROM holding JOIN reached ON holding.%2$s = reached.id
            )
            SELECT departments.* FROM departments JOIN reached ON reached.id = departments.id
            WHERE departments.id <> ?
            ORDER BY departments.sort_key, departments.code, departments.id
            SQL, $holding->toSql(), $from, $to);

        return Department::fromQuery($sql, [...$holding->getBindings(), $departmentId, $departmentId]);
    }
}
