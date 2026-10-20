<?php

namespace App\Queries\Master;

use App\Models\Master\CompanyPost;
use App\Models\Master\CompanyPostTerm;
use App\Models\Master\Department;
use App\Models\Master\DepartmentTerm;
use App\Models\Master\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * 共通マスタの検索(docs の 設計/共通マスタ/検索と組織図.md 2 節): ヘッダーの検索欄の候補のユーザーと組織。読み取りは行を絞り込まない
 * (判定はルートの認可だけ)。返す形の画面の型は resources/js/types/master.ts の MasterSearchResult。
 *
 * - 語が空なら語で絞らない(参照の選択欄が開いた直後に先頭を出す。ヘッダーの検索欄は語が空なら呼ばない)。以下の「語を含む」の条件は付けず、並びと上限だけを適用する。
 * - ユーザー = d に有効なユーザーのうち、d の氏名・検索用名称(ロケールの行だけ)・ユーザーコードのどれかが語を部分一致で含むもの。
 * - 組織 = 組織図の木にある組織({@see OrganizationChartQuery::treeDepartmentsOn()})のうち、d の名称・検索用名称(ロケールの行だけ)・
 *   組織コードのどれかが語を含むもの(木と検索の集合を揃える = 方針 3)。
 * - 部分一致は大文字小文字を区別しない(ILIKE)。語の中の %・_・\ は文字そのものとして扱う。正規化はしない(方針 4)。
 * - 並びはユーザーも組織も sort_key・code の順で、先頭から各 10 件まで(件数は引数で変えられる。ワークフローの候補の公開の口 Api\MasterCandidates が
 *   同じ規則を件数を変えて呼ぶ = docs の 設計/ワークフロー/ルートの深さ/段2の設計への反映.md 3 節。役職の候補も同じ規則で引く)。名称が無ければ null(画面がコードを出す)。
 * - ユーザーの主所属 = {@see MainDepartmentQuery}(組織が木にあるもの)。無ければ null。
 *
 * @phpstan-import-type NamedDepartment from OrganizationChartQuery
 *
 * @phpstan-type SearchedUser array{id: int, code: string, name: string|null, mainDepartment: NamedDepartment|null}
 */
class MasterSearchQuery
{
    /** ユーザー・組織のそれぞれの上限 */
    public const LIMIT = 10;

    public function __construct(
        private OrganizationChartQuery $chart,
        private MainDepartmentQuery $mainDepartments,
        private TermQuery $terms,
    ) {}

    /**
     * ヘッダーの検索欄の候補(ユーザーと組織をそれぞれ上限まで)。
     *
     * @return array{users: list<SearchedUser>, departments: list<NamedDepartment>}
     */
    public function search(string $keyword, DateTimeInterface $date, string $localeCode): array
    {
        return [
            'users' => $this->users($keyword, $date, $localeCode, self::LIMIT),
            'departments' => $this->departments($keyword, $date, $localeCode, self::LIMIT),
        ];
    }

    /**
     * 語を含む d に有効なユーザー(sort_key・code の順で $limit 件まで)と、その主所属(組織が木にあるもの)。
     *
     * @return list<SearchedUser>
     */
    public function users(string $keyword, DateTimeInterface $date, string $localeCode, int $limit): array
    {
        $users = $this->matchingUsers($this->patternOf($keyword), $date, $localeCode, $limit);
        $mainDepartments = $this->mainDepartments->mainDepartmentsOn(array_values($users->modelKeys()), $date, $localeCode);
        $userTerms = $this->terms->userTermsOn(array_values($users->modelKeys()), $date, $localeCode);

        return array_values($users->map(fn (User $user) => [
            'id' => $user->id,
            'code' => $user->code,
            'name' => $userTerms->get($user->id)?->userTermTranslations->first()?->name,
            'mainDepartment' => $mainDepartments[$user->id] ?? null,
        ])->all());
    }

    /**
     * 語を含む木の組織(sort_key・code の順で $limit 件まで)。
     *
     * @return list<NamedDepartment>
     */
    public function departments(string $keyword, DateTimeInterface $date, string $localeCode, int $limit): array
    {
        $departments = $this->matchingDepartments($this->patternOf($keyword), $this->treeIdsOn($date), $date, $localeCode, $limit);
        $departmentNames = $this->departmentNames(array_values($departments->modelKeys()), $date, $localeCode);

        return array_values($departments->map(fn (Department $department) => $this->namedDepartment($department, $departmentNames))->all());
    }

    /**
     * 語を含む d に有効な役職(組織セットで絞らない。sort_key・code の順で $limit 件まで)。役職のロケール別の行は検索用名称を持たないので、名称とコードを見る。
     *
     * @return list<array{id: int, code: string, name: string|null}>
     */
    public function companyPosts(string $keyword, DateTimeInterface $date, string $localeCode, int $limit): array
    {
        $pattern = $this->patternOf($keyword);
        $posts = CompanyPost::query()
            ->effectiveOn($date)
            ->when($pattern, fn (Builder $query, string $pattern) => $query->where(fn (Builder $match) => $match
                ->whereLike('company_posts.code', $pattern)
                ->orWhereHas('companyPostTerms', fn (Builder $terms) => $terms
                    ->effectiveOn($date)
                    ->whereHas('companyPostTermTranslations', fn (Builder $translations) => $translations
                        ->whereRelation('locale', 'code', $localeCode)
                        ->whereLike('company_post_term_translations.name', $pattern)))))
            ->orderBy('sort_key')
            ->orderBy('code')
            ->limit($limit)
            ->get();
        $names = $this->terms->companyPostTermsOn(array_values($posts->modelKeys()), $date, $localeCode)
            ->map(fn (CompanyPostTerm $term) => $term->companyPostTermTranslations->first()?->name)
            ->all();

        return array_values($posts->map(fn (CompanyPost $post) => ['id' => $post->id, 'code' => $post->code, 'name' => $names[$post->id] ?? null])->all());
    }

    /**
     * 語の部分一致の型(%・_・\ は文字そのもの)。語が空なら null(絞らない)。
     */
    private function patternOf(string $keyword): ?string
    {
        return $keyword === '' ? null : '%'.addcslashes($keyword, '\\%_').'%';
    }

    /**
     * 組織図の木の組織の id({@see OrganizationChartQuery::treeDepartmentsOn()})。
     *
     * @return list<int>
     */
    private function treeIdsOn(DateTimeInterface $date): array
    {
        return array_values($this->chart->treeDepartmentsOn($date)->map(fn (Department $department) => $department->id)->all());
    }

    /**
     * 語を含む(語が空なら絞らない)d に有効なユーザー(sort_key・code の順で $limit 件まで)。
     *
     * @return Collection<int, User>
     */
    private function matchingUsers(?string $pattern, DateTimeInterface $date, string $localeCode, int $limit): Collection
    {
        return User::query()
            ->effectiveOn($date)
            ->when($pattern, fn (Builder $query, string $pattern) => $query->where(fn (Builder $match) => $match
                ->whereLike('users.code', $pattern)
                ->orWhereHas('userTerms', fn (Builder $terms) => $terms
                    ->effectiveOn($date)
                    ->whereHas('userTermTranslations', fn (Builder $translations) => $this->namedLike($translations, 'user_term_translations', $pattern, $localeCode)))))
            ->orderBy('sort_key')
            ->orderBy('code')
            ->limit($limit)
            ->get();
    }

    /**
     * 語を含む(語が空なら絞らない)木の組織(sort_key・code の順で $limit 件まで)。
     *
     * @param  list<int>  $treeIds
     * @return Collection<int, Department>
     */
    private function matchingDepartments(?string $pattern, array $treeIds, DateTimeInterface $date, string $localeCode, int $limit): Collection
    {
        return Department::query()
            ->whereKey($treeIds)
            ->when($pattern, fn (Builder $query, string $pattern) => $query->where(fn (Builder $match) => $match
                ->whereLike('departments.code', $pattern)
                ->orWhereHas('departmentTerms', fn (Builder $terms) => $terms
                    ->effectiveOn($date)
                    ->whereHas('departmentTermTranslations', fn (Builder $translations) => $this->namedLike($translations, 'department_term_translations', $pattern, $localeCode)))))
            ->orderBy('sort_key')
            ->orderBy('code')
            ->limit($limit)
            ->get();
    }

    /**
     * ロケール別の行のうち、ロケールが渡したもので、名称か検索用名称が語を含むもの(大文字小文字を区別しない)。
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $translations
     * @return Builder<TModel>
     */
    private function namedLike(Builder $translations, string $table, string $pattern, string $localeCode): Builder
    {
        return $translations
            ->whereRelation('locale', 'code', $localeCode)
            ->where(fn (Builder $names) => $names->whereLike("{$table}.name", $pattern)->orWhereLike("{$table}.search_name", $pattern));
    }

    /**
     * 組織の d・ロケールの名称(組織の id → 名称。行が無ければ入らない)。
     *
     * @param  list<int>  $departmentIds
     * @return array<int, string|null>
     */
    private function departmentNames(array $departmentIds, DateTimeInterface $date, string $localeCode): array
    {
        return $this->terms->departmentTermsOn(array_values(array_unique($departmentIds)), $date, $localeCode)
            ->map(fn (DepartmentTerm $term) => $term->departmentTermTranslations->first()?->name)
            ->all();
    }

    /**
     * @param  array<int, string|null>  $names
     * @return NamedDepartment
     */
    private function namedDepartment(Department $department, array $names): array
    {
        return ['id' => $department->id, 'code' => $department->code, 'name' => $names[$department->id] ?? null];
    }
}
