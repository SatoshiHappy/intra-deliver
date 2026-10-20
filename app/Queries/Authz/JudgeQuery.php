<?php

namespace App\Queries\Authz;

use App\Models\Master\User;
use App\Models\Master\UserTerm;
use App\Queries\Master\MembershipQuery;
use App\Queries\Master\TermQuery;
use App\Support\QueryParameters;
use DateTimeInterface;

/**
 * 認可の確認の画面の、判定する人(docs の 設計/共通マスタ/ロールと認可/認可の確認/認可設定の画面.md 1.4): 選べる人の候補、クエリ user の読み方、判定した人とその主所属。
 * 選べる人 = ゲストと、d に有効なユーザーのすべて(ユーザーの並びは sort_key・code、名称は d・ロケールの氏名。行が無ければ null)。
 *
 * @phpstan-type JudgeUser array{id: int, code: string, name: string|null}
 */
class JudgeQuery
{
    /** クエリ user でゲストを表す値 */
    public const GUEST = 'guest';

    public function __construct(
        private MembershipQuery $memberships,
        private TermQuery $terms,
    ) {}

    /**
     * クエリ user の値から判定する人(null = ゲスト)。無い = 自分、guest = ゲスト、整数 = その ID のユーザー。整数でも guest でもない値、
     * 存在しないユーザー、d に有効でないユーザーは、無いのと同じ(自分。検証のエラーにしない)。
     *
     * @param  int|null  $selfUserId  自分(今の利用者。null = ゲスト)
     */
    public function judgeUserIdOf(?string $selection, ?int $selfUserId, DateTimeInterface $date): ?int
    {
        if ($selection === self::GUEST) {
            return null;
        }

        $userId = QueryParameters::integerOf($selection);

        return $userId !== null && User::query()->whereKey($userId)->effectiveOn($date)->exists() ? $userId : $selfUserId;
    }

    /**
     * 判定する人の候補のうちユーザー(ゲストは含めない。画面が先頭に足す)。
     *
     * @return list<JudgeUser>
     */
    public function candidatesOn(DateTimeInterface $date, string $localeCode): array
    {
        $users = User::query()->effectiveOn($date)->orderBy('sort_key')->orderBy('code')->get();
        $terms = $this->terms->userTermsOn(array_values($users->modelKeys()), $date, $localeCode);

        return array_values($users->map(fn (User $user) => [
            'id' => $user->id,
            'code' => $user->code,
            'name' => $terms->get($user->id)?->userTermTranslations->first()?->name,
        ])->all());
    }

    /**
     * 判定した人と、その d の主所属の組織(docs の 設計/デモユーザー切替/今の利用者.md 5.5 の 1〜3 で選ばれる所属の組織。選んだ組織は無い)。
     * ゲスト・主所属が無いときの組織は null。
     *
     * @return array{user: JudgeUser|null, department: array{id: int, code: string, name: string|null}|null}
     */
    public function judgeOn(?int $userId, DateTimeInterface $date, string $localeCode): array
    {
        $userTerm = $userId === null ? null : $this->terms->userTermOn($userId, $date, $localeCode);

        if ($userId === null || $userTerm === null) {
            return ['user' => null, 'department' => null];
        }

        $membership = $this->memberships->currentMembershipOn($userId, null, $date);
        $departmentTerm = $membership === null ? null : $this->terms->departmentTermOn($membership->department_id, $date, $localeCode);

        return [
            'user' => $this->judgeUser($userTerm),
            'department' => $membership === null ? null : [
                'id' => $membership->department_id,
                'code' => (string) $membership->department?->code,
                'name' => $departmentTerm?->departmentTermTranslations->first()?->name,
            ],
        ];
    }

    /**
     * @return JudgeUser
     */
    private function judgeUser(UserTerm $userTerm): array
    {
        return [
            'id' => $userTerm->user_id,
            'code' => (string) $userTerm->user?->code,
            'name' => $userTerm->userTermTranslations->first()?->name,
        ];
    }
}
