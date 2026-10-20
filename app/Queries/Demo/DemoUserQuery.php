<?php

namespace App\Queries\Demo;

use App\Models\Master\DepartmentMembership;
use App\Models\Master\DepartmentMembershipPost;
use App\Models\Master\User;
use App\Queries\Master\MembershipQuery;
use App\Queries\Master\TermQuery;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * 切替の候補を引く 1 か所(docs の 設計/デモユーザー切替/デモの取り付け.md 6.5): config/demo.php の users のうち、d に有効なユーザー
 * (docs の 設計/共通マスタ/共通の規則.md 5.4。Master\User の effectiveOn)。利用者メニューの節の項目も、ホームの役割カードも、切替の入口の確かめも、これを使う。
 */
class DemoUserQuery
{
    public function __construct(
        private TermQuery $terms,
        private MembershipQuery $memberships,
    ) {}

    /**
     * d の候補。並びは一覧の規則(ユーザーの並び順、次にコード = 共通の規則 9 節)。一覧に無いユーザーと d に有効でないユーザーは入らない。
     * 氏名は d・ロケールの値で、ロケール別の行が無ければ null(候補の全員の分を 1 回で引く)。役どころと試せることは一覧の値。
     *
     * @return list<DemoUser>
     */
    public function candidatesOn(DateTimeInterface $date, string $localeCode): array
    {
        $codes = array_map(strval(...), array_keys(config()->array('demo.users')));
        $users = User::query()
            ->whereIn('code', $codes)
            ->effectiveOn($date)
            ->orderBy('sort_key')
            ->orderBy('code')
            ->get();

        $userTerms = $this->terms->userTermsOn(array_values($users->map(fn (User $user) => $user->id)->all()), $date, $localeCode);

        return array_values($users->map(fn (User $user) => new DemoUser(
            id: $user->id,
            code: $user->code,
            name: $userTerms->get($user->id)?->userTermTranslations->first()?->name,
            role: config()->string("demo.users.{$user->code}.role"),
            can: config()->string("demo.users.{$user->code}.can"),
        ))->all());
    }

    /**
     * 候補の所属の表示(ホームの役割カード = デモの取り付け.md 6.3): d の主所属(カレント組織の候補 = 既定の組織セットの所属のうち主所属)の組織名と、
     * その所属の d に成り立つ役職名(役職の並び順・コードの順)を空白で繋いだもの。役職が無ければ組織名だけ、主所属が無ければ null。
     * 名前は d・ロケールの値で、ロケール別の行が無ければコード(画面が名前の無いものをコードで出す規則 = docs の 設計/デモユーザー切替/README.md の方針 9)。
     *
     * @param  list<DemoUser>  $candidates
     * @return array<int, string|null> ユーザーの id → 所属の表示
     */
    public function affiliationsOn(array $candidates, DateTimeInterface $date, string $localeCode): array
    {
        $mainMemberships = [];

        foreach ($candidates as $candidate) {
            $mainMemberships[$candidate->id] = $this->memberships->currentMembershipAmong($this->memberships->currentDepartmentCandidatesOn($candidate->id, $date), null);
        }

        $held = new Collection(array_values(array_filter($mainMemberships)));
        $departmentTerms = $this->terms->departmentTermsOn(array_values($held->map(fn (DepartmentMembership $membership) => $membership->department_id)->all()), $date, $localeCode);
        $postIds = $held->flatMap(fn (DepartmentMembership $membership) => $membership->departmentMembershipPosts->map(fn (DepartmentMembershipPost $post) => $post->company_post_id));
        $postTerms = $this->terms->companyPostTermsOn(array_values($postIds->unique()->all()), $date, $localeCode);

        return array_map(fn (?DepartmentMembership $membership) => $membership === null ? null : implode(' ', [
            $departmentTerms->get($membership->department_id)?->departmentTermTranslations->first()->name ?? (string) $membership->department?->code,
            ...$membership->departmentMembershipPosts
                ->map(fn (DepartmentMembershipPost $post) => $postTerms->get($post->company_post_id)?->companyPostTermTranslations->first()->name ?? (string) $post->companyPost?->code)
                ->all(),
        ]), $mainMemberships);
    }
}
