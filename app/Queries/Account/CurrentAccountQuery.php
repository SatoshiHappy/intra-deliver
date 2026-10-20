<?php

namespace App\Queries\Account;

use App\Models\Master\DepartmentMembership;
use App\Models\Master\User;
use App\Queries\Master\MembershipQuery;
use App\Queries\Master\TermQuery;
use App\Support\AccessContext;
use DateTimeInterface;
use Illuminate\Support\Arr;

/**
 * 今の利用者(docs の 設計/デモユーザー切替/今の利用者.md 5.4・5.7): 今の利用者が d に有効か(ガードから外すミドルウェアが使う)と、
 * 共有プロパティ account の値(配列。形は {@see self::currentAccountOf()} の戻り値)。
 *
 * 候補は {@see MembershipQuery::currentDepartmentCandidatesOn()} で 1 回だけ引き、カレント組織は同じ候補から
 * {@see MembershipQuery::currentMembershipAmong()}(5.5 の選ぶ規則の 1 か所)で選ぶ。ここでは名称を足すだけにする。
 */
class CurrentAccountQuery
{
    public function __construct(
        private MembershipQuery $memberships,
        private TermQuery $terms,
    ) {}

    /**
     * ユーザーが d に有効か(docs の 設計/共通マスタ/共通の規則.md 5.4。Master\User の effectiveOn)。存在しないユーザーは有効でない。
     */
    public function isEffectiveUserOn(int $userId, DateTimeInterface $date): bool
    {
        return User::query()->whereKey($userId)->effectiveOn($date)->exists();
    }

    /**
     * 共有プロパティ account の値: 判定の入力の今の利用者・カレント組織(5.5)・カレント組織の候補と、その d・ロケールの名称。
     * ユーザーがいないか、d に有効でなければゲスト(user も currentDepartment も null、departments は空)。
     * currentDepartment と departments の id は組織(departments 表)の ID で、切替で送る department_id に当たる。
     * name は d・ロケールの名称で、行が無ければ null(画面がコードを出す = docs の 設計/デモユーザー切替/README.md の方針 9)。
     * 画面の型は resources/js/types/account.ts。
     *
     * @return array{user: array{id: int, code: string, name: string|null}|null, currentDepartment: array{id: int, code: string, name: string|null}|null, departments: list<array{id: int, code: string, name: string|null, isMain: bool}>}
     */
    public function currentAccountOf(AccessContext $context): array
    {
        $userTerm = $context->userId === null ? null : $this->terms->userTermOn($context->userId, $context->date, $context->localeCode);

        if ($context->userId === null || $userTerm === null) {
            return ['user' => null, 'currentDepartment' => null, 'departments' => []];
        }

        $candidates = $this->memberships->currentDepartmentCandidatesOn($context->userId, $context->date);
        $currentMembership = $this->memberships->currentMembershipAmong($candidates, $context->selectedDepartmentId);
        $departmentTerms = $this->terms->departmentTermsOn(
            array_values($candidates->map(fn (DepartmentMembership $membership) => $membership->department_id)->all()),
            $context->date,
            $context->localeCode,
        );
        $departments = array_values($candidates
            ->map(fn (DepartmentMembership $membership) => [
                'id' => $membership->department_id,
                'code' => (string) $membership->department?->code,
                'name' => $departmentTerms->get($membership->department_id)?->departmentTermTranslations->first()?->name,
                'isMain' => $membership->is_main,
            ])
            ->all());
        $current = Arr::first($departments, fn (array $department) => $department['id'] === $currentMembership?->department_id);

        return [
            'user' => ['id' => $context->userId, 'code' => (string) $userTerm->user?->code, 'name' => $userTerm->userTermTranslations->first()?->name],
            'currentDepartment' => $current === null ? null : ['id' => $current['id'], 'code' => $current['code'], 'name' => $current['name']],
            'departments' => $departments,
        ];
    }
}
