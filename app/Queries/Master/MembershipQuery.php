<?php

namespace App\Queries\Master;

use App\Models\Master\CompanyPost;
use App\Models\Master\DepartmentMembership;
use App\Models\Master\DepartmentMembershipPost;
use App\Models\Master\DepartmentSet;
use App\Support\Period;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * ユーザーの所属と所属役職(docs の 設計/共通マスタ/組織とユーザー.md 8 節・9 節「ユーザーの所属組織」)。
 */
class MembershipQuery
{
    /**
     * ユーザーの d に成り立つ所属(兼務を含むすべて。主所属は is_main)。
     * 所属が成り立つ = 所属の行・組織・ユーザーが d に有効(共通の規則 5.4。DepartmentMembership の holdsOn)。
     * 組織のアンカーを department に、d に成り立つ所属役職(役職も d に有効)を departmentMembershipPosts に、
     * その役職のアンカー(ランク)を departmentMembershipPosts.companyPost に読み込む。
     * 並びは一覧の規則(共通の規則 9 節)のソートキー、id の順で、所属役職の並びは役職のソートキー、コードの順(DB の ORDER BY)。
     *
     * @return Collection<int, DepartmentMembership>
     */
    public function membershipsOn(int $userId, DateTimeInterface $date): Collection
    {
        return $this->membershipsQueryOn([$userId], $date)->get();
    }

    /**
     * 組織の d に成り立つ所属(主所属と兼務。下位の組織の所属は含まない。docs の 設計/共通マスタ/検索と組織図.md 1.3)。
     * ユーザーのアンカーを user に、d に成り立つ所属役職(役職も d に有効)を departmentMembershipPosts に、その役職のアンカーを
     * departmentMembershipPosts.companyPost に読み込む。並びは一覧の規則(共通の規則 9 節)のソートキー、id の順で、所属役職の並びは役職の
     * ソートキー、コードの順(DB の ORDER BY)。
     *
     * @return Collection<int, DepartmentMembership>
     */
    public function membershipsOfDepartmentOn(int $departmentId, DateTimeInterface $date): Collection
    {
        return DepartmentMembership::query()
            ->where('department_id', $departmentId)
            ->holdsOn($date)
            ->with('user')
            ->with('departmentMembershipPosts', fn (Relation $posts) => $this->postsOn($posts, $date))
            ->orderBy('sort_key')
            ->orderBy('id')
            ->get();
    }

    /**
     * 組織たちに d に所属が成り立つユーザーの id(主所属と兼務。下位の組織の所属は含まない。昇順・重ねない)。役職を渡せば、その所属の所属役職も
     * d に成り立ち(役職も d に有効)、役職がそれであるユーザーだけ(設計/共通マスタ/組織とユーザー.md 9 節「組織に当たるユーザー」「組織 + 役職に当たるユーザー」)。
     * 所属が成り立つ = 所属の行・組織・ユーザーが d に有効(共通の規則 5.4)なので、d に有効でない組織・ユーザーの所属は数えない。
     *
     * @param  list<int>  $departmentIds
     * @return list<int>
     */
    public function memberUserIdsOn(array $departmentIds, ?int $companyPostId, DateTimeInterface $date): array
    {
        return array_values(DepartmentMembership::query()
            ->whereIn('department_id', $departmentIds)
            ->holdsOn($date)
            ->when($companyPostId !== null, fn (Builder $memberships) => $memberships->whereHas(
                'departmentMembershipPosts',
                fn (Builder $posts) => $posts->holdsOn($date)->where('company_post_id', $companyPostId),
            ))
            ->distinct()
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(fn (mixed $userId) => (int) $userId)
            ->all());
    }

    /**
     * ユーザーたちの d に成り立つ主所属のうち、組織が渡した組織のどれかであるもの(ユーザーの id → 所属。共通マスタの検索のユーザーの主所属 =
     * docs の 設計/共通マスタ/検索と組織図.md 2 節)。同じ組織セットの主所属は期間が重ならない(DB の排他制約)ので、渡す組織が 1 つの組織セットの
     * ものなら、ユーザーごとに高々 1 件。
     *
     * @param  list<int>  $userIds
     * @param  list<int>  $departmentIds
     * @return Collection<int, DepartmentMembership>
     */
    public function mainMembershipsAmongOn(array $userIds, array $departmentIds, DateTimeInterface $date): Collection
    {
        return DepartmentMembership::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('department_id', $departmentIds)
            ->where('is_main', true)
            ->holdsOn($date)
            ->with('department')
            ->get()
            ->keyBy(fn (DepartmentMembership $membership) => $membership->user_id);
    }

    /**
     * カレント組織の候補(docs の 設計/デモユーザー切替/今の利用者.md 5.5 の 1): ユーザーの d に成り立つ所属のうち、
     * 既定の組織セット(department_sets.is_default)の組織のもの。読み込むものと並びは {@see self::membershipsOn()} と同じ。
     *
     * @return Collection<int, DepartmentMembership>
     */
    public function currentDepartmentCandidatesOn(int $userId, DateTimeInterface $date): Collection
    {
        return $this->currentDepartmentCandidatesAmongOn([$userId], $date)[$userId];
    }

    /**
     * ユーザーそれぞれのカレント組織の候補({@see self::currentDepartmentCandidatesOn()} と同じ候補を同じ並びで)。
     *
     * @param  list<int>  $userIds
     * @return array<int, Collection<int, DepartmentMembership>> キー = 渡したユーザーすべて(候補が無ければ空)
     */
    public function currentDepartmentCandidatesAmongOn(array $userIds, DateTimeInterface $date): array
    {
        $candidates = [];

        foreach ($userIds as $userId) {
            $candidates[$userId] = new Collection;
        }

        if ($candidates === []) {
            return [];
        }

        $memberships = $this->membershipsQueryOn(array_keys($candidates), $date)
            ->whereIn('department_set_id', DepartmentSet::query()->select('id')->where('is_default', true))
            ->get();

        foreach ($memberships as $membership) {
            $candidates[$membership->user_id]->push($membership);
        }

        return $candidates;
    }

    /**
     * カレント組織の所属(docs の 設計/デモユーザー切替/今の利用者.md 5.5): ユーザーの d の候補を引いて
     * {@see self::currentMembershipAmong()} に渡す。
     *
     * @param  int|null  $selectedDepartmentId  利用者が選んだ組織(departments.id)。null = 選んでいない
     */
    public function currentMembershipOn(int $userId, ?int $selectedDepartmentId, DateTimeInterface $date): ?DepartmentMembership
    {
        return $this->currentMembershipAmong($this->currentDepartmentCandidatesOn($userId, $date), $selectedDepartmentId);
    }

    /**
     * 読み込んだ候補({@see self::currentDepartmentCandidatesOn()})からカレント組織の所属を選ぶ規則(docs の 設計/デモユーザー切替/今の利用者.md
     * 5.5 の 2〜4): 選んだ組織が候補にあればその所属、無ければ候補のうち主所属の最初のもの、それも無ければ null。
     * 選んだ組織が候補に無くても例外にしない(異動・所属の無効化で候補から外れたときも主所属へ落ちる)。候補を既に持つ呼び手が使う。
     *
     * @param  Collection<int, DepartmentMembership>  $candidates
     * @param  int|null  $selectedDepartmentId  利用者が選んだ組織(departments.id)。null = 選んでいない
     */
    public function currentMembershipAmong(Collection $candidates, ?int $selectedDepartmentId): ?DepartmentMembership
    {
        return $candidates->first(fn (DepartmentMembership $membership) => $membership->department_id === $selectedDepartmentId)
            ?? $candidates->first(fn (DepartmentMembership $membership) => $membership->is_main);
    }

    /**
     * ユーザーの、組織セットの中の主所属が有効な日の全体(有効な主所属の行の期間の和)。
     * 「d に有効な所属があるなら、d に有効な主所属が 1 つある」(組織とユーザー 8 節)を業務処理が確かめるのに使う。
     * 数える範囲は暫定で組織セットの中(設計/共通マスタ/組織とユーザー.md 8 節)。
     *
     * @return list<Period>
     */
    public function mainMembershipPeriods(int $userId, int $departmentSetId): array
    {
        return Period::union(DepartmentMembership::query()
            ->where('user_id', $userId)
            ->where('department_set_id', $departmentSetId)
            ->where('is_main', true)
            ->get()
            ->map(fn (DepartmentMembership $membership) => $membership->effectivePeriod())
            ->filter()
            ->all());
    }

    /**
     * ユーザーの所属のうち、有効な行の有効な期間(行ごと。和にしない)。
     * ユーザーの有効な日を減らす業務処理が、所属がはみ出さないかを確かめるのに使う(共通の規則 5.5 の 3 行目)。
     *
     * @return array<int, Period>
     */
    public function effectivePeriodsOfUser(int $userId): array
    {
        return DepartmentMembership::query()->where('user_id', $userId)->get()
            ->map(fn (DepartmentMembership $membership) => $membership->effectivePeriod())
            ->filter()
            ->values()
            ->all();
    }

    /**
     * d に成り立つ所属の問い合わせ(読み込むものと並びは {@see self::membershipsOn()} の説明のとおり)。
     *
     * @param  list<int>  $userIds
     * @return Builder<DepartmentMembership>
     */
    private function membershipsQueryOn(array $userIds, DateTimeInterface $date): Builder
    {
        return DepartmentMembership::query()
            ->whereRaw('department_memberships.user_id = ANY (?::bigint[])', ['{'.implode(',', $userIds).'}'])
            ->holdsOn($date)
            ->with('department')
            ->with('departmentMembershipPosts', fn (Relation $posts) => $this->postsOn($posts, $date))
            ->orderBy('sort_key')
            ->orderBy('id');
    }

    /**
     * 所属役職の読み込みの制約: d に成り立つもの(役職も d に有効)を、役職のアンカーつきで、役職のソートキー、コードの順(DB の ORDER BY)に読む。
     * メモリ上の比較は数字だけのコードを数値で比べ、DB の文字列の順と割れるので、並びは DB に任せる(共通の規則 9 節)。
     *
     * @param  Relation<DepartmentMembershipPost, DepartmentMembership, Collection<int, DepartmentMembershipPost>>  $posts
     */
    private function postsOn(Relation $posts, DateTimeInterface $date): void
    {
        $post = fn (string $column) => CompanyPost::query()->select($column)->whereColumn('company_posts.id', 'department_membership_posts.company_post_id');

        $posts->holdsOn($date)->with('companyPost')->orderBy($post('sort_key'))->orderBy($post('code'));
    }
}
