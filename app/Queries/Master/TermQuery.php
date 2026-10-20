<?php

namespace App\Queries\Master;

use App\Models\Master\CompanyPostTerm;
use App\Models\Master\DepartmentTerm;
use App\Models\Master\UserTerm;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * 期間化するマスタ(組織・役職・ユーザー)の「d 時点の値」(docs の 設計/共通マスタ/共通の規則.md 5.4)。
 *
 * d 時点の値 = アンカーと、d に有効な期間の行(高々 1 行)と、その期間のロケール別の行。
 * d に有効な期間の行が無ければ null(その事柄は d には無い)。期間の行はあるが要求したロケールの行が無ければ、
 * ロケール別の行の一覧が空の期間の行を返す(代わりのロケールの選び方は多言語の設計で決めるので、ここでは選ばない)。
 * 複数の事柄の名称を出す呼び手は、複数形の口(ID の一覧を受けて 1 回で引く)を使う。
 */
class TermQuery
{
    /**
     * 組織の d 時点の値。ロケール別の行は departmentTermTranslations(0 か 1 件)、アンカーは department に読み込む。
     */
    public function departmentTermOn(int $departmentId, DateTimeInterface $date, string $localeCode): ?DepartmentTerm
    {
        return $this->departmentTermsQueryOn([$departmentId], $date, $localeCode)->with('department')->first();
    }

    /**
     * 組織の一覧の d 時点の値(組織の ID → 期間の行)。d に有効な期間の行が無い組織は入らない。ロケール別の行は
     * departmentTermTranslations(0 か 1 件)に読み込み、アンカーは読み込まない。
     *
     * @param  list<int>  $departmentIds
     * @return Collection<int, DepartmentTerm>
     */
    public function departmentTermsOn(array $departmentIds, DateTimeInterface $date, string $localeCode): Collection
    {
        return $this->departmentTermsQueryOn($departmentIds, $date, $localeCode)->get()->keyBy(fn (DepartmentTerm $term) => $term->department_id);
    }

    /**
     * 役職の d 時点の値。ロケール別の行は companyPostTermTranslations(0 か 1 件)、アンカー(ランクを持つ)は companyPost に読み込む。
     */
    public function companyPostTermOn(int $companyPostId, DateTimeInterface $date, string $localeCode): ?CompanyPostTerm
    {
        return $this->companyPostTermsQueryOn([$companyPostId], $date, $localeCode)->with('companyPost')->first();
    }

    /**
     * 役職の一覧の d 時点の値(役職の ID → 期間の行)。d に有効な期間の行が無い役職は入らない。ロケール別の行は
     * companyPostTermTranslations(0 か 1 件)に読み込み、アンカーは読み込まない。
     *
     * @param  list<int>  $companyPostIds
     * @return Collection<int, CompanyPostTerm>
     */
    public function companyPostTermsOn(array $companyPostIds, DateTimeInterface $date, string $localeCode): Collection
    {
        return $this->companyPostTermsQueryOn($companyPostIds, $date, $localeCode)->get()->keyBy(fn (CompanyPostTerm $term) => $term->company_post_id);
    }

    /**
     * ユーザーの d 時点の値。ロケール別の行は userTermTranslations(0 か 1 件)、アンカーは user に読み込む。
     */
    public function userTermOn(int $userId, DateTimeInterface $date, string $localeCode): ?UserTerm
    {
        return $this->userTermsQueryOn([$userId], $date, $localeCode)->with('user')->first();
    }

    /**
     * ユーザーの一覧の d 時点の値(ユーザーの ID → 期間の行)。d に有効な期間の行が無いユーザーは入らない。ロケール別の行は
     * userTermTranslations(0 か 1 件)に読み込み、アンカーは読み込まない。
     *
     * @param  list<int>  $userIds
     * @return Collection<int, UserTerm>
     */
    public function userTermsOn(array $userIds, DateTimeInterface $date, string $localeCode): Collection
    {
        return $this->userTermsQueryOn($userIds, $date, $localeCode)->get()->keyBy(fn (UserTerm $term) => $term->user_id);
    }

    /**
     * 組織の d に有効な期間の行と、そのロケール別の行の問い合わせ。
     *
     * @param  list<int>  $departmentIds
     * @return Builder<DepartmentTerm>
     */
    private function departmentTermsQueryOn(array $departmentIds, DateTimeInterface $date, string $localeCode): Builder
    {
        return DepartmentTerm::query()
            ->whereIn('department_id', $departmentIds)
            ->effectiveOn($date)
            ->with('departmentTermTranslations', fn (Relation $translations) => $translations->whereRelation('locale', 'code', $localeCode));
    }

    /**
     * 役職の d に有効な期間の行と、そのロケール別の行の問い合わせ。
     *
     * @param  list<int>  $companyPostIds
     * @return Builder<CompanyPostTerm>
     */
    private function companyPostTermsQueryOn(array $companyPostIds, DateTimeInterface $date, string $localeCode): Builder
    {
        return CompanyPostTerm::query()
            ->whereIn('company_post_id', $companyPostIds)
            ->effectiveOn($date)
            ->with('companyPostTermTranslations', fn (Relation $translations) => $translations->whereRelation('locale', 'code', $localeCode));
    }

    /**
     * ユーザーの d に有効な期間の行と、そのロケール別の行の問い合わせ。
     *
     * @param  list<int>  $userIds
     * @return Builder<UserTerm>
     */
    private function userTermsQueryOn(array $userIds, DateTimeInterface $date, string $localeCode): Builder
    {
        return UserTerm::query()
            ->whereIn('user_id', $userIds)
            ->effectiveOn($date)
            ->with('userTermTranslations', fn (Relation $translations) => $translations->whereRelation('locale', 'code', $localeCode));
    }
}
