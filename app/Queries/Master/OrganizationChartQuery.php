<?php

namespace App\Queries\Master;

use App\Models\Master\Department;
use App\Models\Master\DepartmentMembership;
use App\Models\Master\DepartmentMembershipPost;
use App\Models\Master\DepartmentSet;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * 組織図(docs の 設計/共通マスタ/検索と組織図.md 1 節)のページの props の元: 表示する組織セットの d の組織ツリー(1.2)と、
 * 選んだ組織とその所属ユーザー(1.3)。読み取りは行を絞り込まない(ゲストを含む全員に、全員の組織と所属を見せる。判定はルートの認可だけ)。
 * 名称は d・ロケールの値で、行が無ければ null(画面がコードを出す)。返す形の画面の型は resources/js/types/master.ts。
 *
 * @phpstan-type NamedDepartment array{id: int, code: string, name: string|null}
 * @phpstan-type OrganizationNode array{id: int, code: string, name: string|null, children: list<mixed>}
 * @phpstan-type OrganizationMember array{id: int, code: string, name: string|null, isMain: bool, posts: list<array{id: int, code: string, name: string|null}>}
 */
class OrganizationChartQuery
{
    /**
     * 読んだ組織図の木の組織(キー = 基準日)。通常の読み込みで tree と selectedDepartment の両方が使うので 1 回にする。
     * インスタンスの中だけで覚える。singleton・scoped に束縛しない(要求をまたいで覚えない = docs の 設計/メニュー/表示.md 3.1 の 8)。
     *
     * @var array<string, Collection<int, Department>>
     */
    private array $treeDepartments = [];

    public function __construct(
        private DepartmentTreeQuery $trees,
        private MembershipQuery $memberships,
        private TermQuery $terms,
    ) {}

    /**
     * 組織図の木(1.2): 頂点の組織と、頂点から d に成り立つ内包を子へたどって届く組織。兄弟は子の側の内包の sort_key・id の順。
     * 表示する組織セットが無いか、頂点の組織が d に有効でなければ null。
     *
     * @return OrganizationNode|null
     */
    public function treeOn(DateTimeInterface $date, string $localeCode): ?array
    {
        $departments = $this->treeDepartmentsOn($date);
        $root = $departments->first(fn (Department $department) => $department->is_root);

        if ($root === null) {
            return null;
        }

        $names = $this->departmentNames($departments, $date, $localeCode);
        $children = $this->childrenByParentId($departments);
        $placed = [];

        return $this->node($root, $children, $names, $placed);
    }

    /**
     * 選んだ組織と、その所属ユーザー(1.3)。選んだ組織 = 渡した組織が木にあればその組織、無ければ(null・木に無い)頂点の組織。
     * 木が無ければ、選んだ組織は null で所属ユーザーは空。所属ユーザーは選んだ組織への d に成り立つ所属(主所属と兼務。下位の組織は含まない)の
     * ユーザーで、所属の sort_key・id の順。役職は d に成り立つ所属役職の役職で、役職の sort_key・code の順。
     *
     * @return array{selectedDepartment: NamedDepartment|null, members: list<OrganizationMember>}
     */
    public function selectionOn(?int $departmentId, DateTimeInterface $date, string $localeCode): array
    {
        $departments = $this->treeDepartmentsOn($date);
        $selected = $departments->first(fn (Department $department) => $department->id === $departmentId)
            ?? $departments->first(fn (Department $department) => $department->is_root);

        if ($selected === null) {
            return ['selectedDepartment' => null, 'members' => []];
        }

        return [
            'selectedDepartment' => [
                'id' => $selected->id,
                'code' => $selected->code,
                'name' => $this->departmentNames(new Collection([$selected]), $date, $localeCode)[$selected->id] ?? null,
            ],
            'members' => $this->members($selected->id, $date, $localeCode),
        ];
    }

    /**
     * 表示する組織セット(1.2 = 既定の組織セットのうち、会社の並び(sort_key・code)で最初の会社のもの)の d の組織ツリーの組織。
     * 組織セットが無ければ空。共通マスタの検索(2 節)も、木と同じ集合の組織を出すためにこれを使う(方針 3)。
     * 各組織の inclusionsAsChild に、d に成り立つ親との内包を読み込む({@see DepartmentTreeQuery::tree()})。同じインスタンスで同じ基準日には 1 回だけ読む。
     *
     * @return Collection<int, Department>
     */
    public function treeDepartmentsOn(DateTimeInterface $date): Collection
    {
        return $this->treeDepartments[$date->format('Y-m-d')] ??= $this->readTreeDepartmentsOn($date);
    }

    /**
     * 表示する組織セットの d の組織ツリーの組織を読む({@see self::treeDepartmentsOn()} の中身)。
     *
     * @return Collection<int, Department>
     */
    private function readTreeDepartmentsOn(DateTimeInterface $date): Collection
    {
        $departmentSet = DepartmentSet::query()
            ->select('department_sets.*')
            ->join('companies', 'companies.id', '=', 'department_sets.company_id')
            ->where('department_sets.is_default', true)
            ->orderBy('companies.sort_key')
            ->orderBy('companies.code')
            ->first();

        return $departmentSet === null ? new Collection : $this->trees->tree($departmentSet->id, $date);
    }

    /**
     * 木の組織を親の id ごとに、兄弟の並び(子の側の内包の sort_key・id)で分けたもの。
     *
     * @param  Collection<int, Department>  $departments  {@see DepartmentTreeQuery::tree()} の結果(inclusionsAsChild に d に成り立つ親との内包を持つ)
     * @return array<int, list<Department>>
     */
    private function childrenByParentId(Collection $departments): array
    {
        $entries = [];

        foreach ($departments as $department) {
            foreach ($department->inclusionsAsChild as $inclusion) {
                $entries[] = ['parentId' => $inclusion->parent_department_id, 'order' => [$inclusion->sort_key, $inclusion->id], 'department' => $department];
            }
        }

        usort($entries, fn (array $a, array $b) => $a['order'] <=> $b['order']);
        $children = [];

        foreach ($entries as $entry) {
            $children[$entry['parentId']][] = $entry['department'];
        }

        return $children;
    }

    /**
     * 組織とその子孫の節。止まらなかった循環でも終わるように、1 度置いた組織は 2 度置かない。
     *
     * @param  array<int, list<Department>>  $children
     * @param  array<int, string|null>  $names
     * @param  array<int, true>  $placed
     * @return OrganizationNode
     */
    private function node(Department $department, array $children, array $names, array &$placed): array
    {
        $placed[$department->id] = true;
        $nodes = [];

        foreach ($children[$department->id] ?? [] as $child) {
            if (! isset($placed[$child->id])) {
                $nodes[] = $this->node($child, $children, $names, $placed);
            }
        }

        return ['id' => $department->id, 'code' => $department->code, 'name' => $names[$department->id] ?? null, 'children' => $nodes];
    }

    /**
     * 組織の d・ロケールの名称(組織の id → 名称。行が無ければ null)。
     *
     * @param  Collection<int, Department>  $departments
     * @return array<int, string|null>
     */
    private function departmentNames(Collection $departments, DateTimeInterface $date, string $localeCode): array
    {
        $terms = $this->terms->departmentTermsOn(array_values($departments->map(fn (Department $department) => $department->id)->all()), $date, $localeCode);

        return $departments->mapWithKeys(fn (Department $department) => [
            $department->id => $terms->get($department->id)?->departmentTermTranslations->first()?->name,
        ])->all();
    }

    /**
     * 組織の所属ユーザー(1.3)。
     *
     * @return list<OrganizationMember>
     */
    private function members(int $departmentId, DateTimeInterface $date, string $localeCode): array
    {
        $memberships = $this->memberships->membershipsOfDepartmentOn($departmentId, $date);
        $userTerms = $this->terms->userTermsOn(array_values($memberships->map(fn (DepartmentMembership $membership) => $membership->user_id)->unique()->all()), $date, $localeCode);
        $postIds = $memberships->flatMap(fn (DepartmentMembership $membership) => $membership->departmentMembershipPosts->map(fn (DepartmentMembershipPost $post) => $post->company_post_id));
        $postTerms = $this->terms->companyPostTermsOn(array_values($postIds->unique()->all()), $date, $localeCode);

        return array_values($memberships->map(fn (DepartmentMembership $membership) => [
            'id' => $membership->user_id,
            'code' => (string) $membership->user?->code,
            'name' => $userTerms->get($membership->user_id)?->userTermTranslations->first()?->name,
            'isMain' => $membership->is_main,
            'posts' => array_values($membership->departmentMembershipPosts
                ->map(fn (DepartmentMembershipPost $post) => [
                    'id' => $post->company_post_id,
                    'code' => (string) $post->companyPost?->code,
                    'name' => $postTerms->get($post->company_post_id)?->companyPostTermTranslations->first()?->name,
                ])
                ->all()),
        ])->all());
    }
}
