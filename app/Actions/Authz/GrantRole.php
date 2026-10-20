<?php

namespace App\Actions\Authz;

use App\Actions\Master\WritesCommonMaster;
use App\Models\Authz\UserRole;
use App\Queries\Master\EffectivePeriodQuery;
use App\Support\Period;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

/**
 * ユーザーにロールを付与する(docs の 設計/共通マスタ/ロールと認可/ロール.md 3 節)。作る行は有効(is_deleted = false)。
 *
 * 止める規則: 付与が d に有効ならユーザーも d に有効(共通の規則 5.5)。
 * 期間を明示したとき = その期間のまま作り、ユーザーが期間の全体で有効でなければ拒む(切らない)。
 * 登録日だけを渡したとき(既定の期間)= 登録日から、ユーザーが続けて有効な間(本家の既定「登録日～システム終了日の前日」=
 * [登録日, 上限なし) を、ユーザーが有効でない日の手前で切る。メインの決定 2026-10-04)。登録日にユーザーが有効でなければ拒む。
 * 同じロールの付与の重なりは DB が止める。
 */
class GrantRole
{
    use WritesCommonMaster;

    public function __construct(private EffectivePeriodQuery $effectivePeriods) {}

    /**
     * @param  Period|DateTimeInterface  $term  付与の期間、または登録日(既定の期間にする)
     *
     * @throws ValidationException 規則に反するとき
     */
    public function execute(int $userId, int $roleId, Period|DateTimeInterface $term): UserRole
    {
        return $this->writeCommonMaster(function () use ($userId, $roleId, $term) {
            $period = $this->periodFor($userId, $term);

            return UserRole::query()->create([
                'user_id' => $userId,
                'role_id' => $roleId,
                'start_date' => $period->start->toDateString(),
                'end_date' => $period->end?->toDateString(),
                'is_deleted' => false,
            ]);
        });
    }

    /**
     * @throws ValidationException 規則に反するとき
     */
    private function periodFor(int $userId, Period|DateTimeInterface $term): Period
    {
        $userPeriods = $this->effectivePeriods->ofUser($userId);

        if ($term instanceof Period) {
            return Period::covers($userPeriods, $term)
                ? $term
                : throw ValidationException::withMessages(['user_id' => ['ユーザーが付与の期間の全体で有効ではありません']]);
        }

        $span = Period::spanIncluding($userPeriods, $term)
            ?? throw ValidationException::withMessages(['user_id' => ['ユーザーが登録日に有効ではありません']]);

        return new Period($term, $span->end);
    }
}
