<?php

namespace App\Queries\Master\Api;

use App\Models\Master\User;
use App\Models\Master\UserTerm;
use App\Support\Period;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * 基準日 d に有効なユーザー(共通マスタのユーザーの期間と無効の規則 = docs の 設計/共通マスタ/共通の規則.md 5.4。モデルのスコープ effectiveOn の 1 か所)。
 * 判定しない。鍵を取らない。
 *
 * Master の公開の口(主体・認可・検証の範囲・ロック・原子性・結果・失敗の正本 = docs の 設計/ワークフロー/ルートの深さ/段2の設計への反映.md 3 節の表)。
 */
class EffectiveUsers
{
    /**
     * @param  list<int>  $userIds
     * @return list<int> 渡した id のうち d に有効なもの(昇順。重ねない)。無い id は入れない
     */
    public function idsOn(array $userIds, DateTimeInterface $date): array
    {
        if ($userIds === []) {
            return [];
        }

        return array_values(array_map('intval', User::query()->whereKey(array_values(array_unique($userIds)))->effectiveOn($date)->orderBy('id')->pluck('id')->all()));
    }

    /**
     * 期間 [from, to) のどこかの日に有効な人(to が null = 上限なし。docs の 設計/ワークフロー/案件の運用/3aと段2の設計への反映.md の 3 節)。
     * 有効な日は期間の行の {@see UserTerm::effectivePeriod()}(effectiveOn と同じ定義)で数える。
     *
     * @param  list<int>  $userIds
     * @return list<int> 渡した id のうち期間のどこかの日に有効なもの(昇順。重ねない)。無い id は入れない
     */
    public function idsWithin(array $userIds, DateTimeInterface $from, ?DateTimeInterface $to): array
    {
        if ($userIds === []) {
            return [];
        }

        $users = User::query()->with('userTerms')->whereKey(array_values(array_unique($userIds)))->orderBy('id')->get();
        $overlaps = fn (UserTerm $term) => self::overlaps($term->effectivePeriod(), $from, $to);
        $within = $users->filter(fn (User $user) => $user->userTerms->contains($overlaps));

        return array_values(array_map(fn (User $user) => (int) $user->id, $within->all()));
    }

    /**
     * 期間の行の有効な日のうち from 以後で最も早い日が、行の期間と [from, to) の両方に入るか。
     */
    private static function overlaps(?Period $period, DateTimeInterface $from, ?DateTimeInterface $to): bool
    {
        if ($period === null) {
            return false;
        }

        $first = max($period->start->format('Y-m-d'), $from->format('Y-m-d'));

        return $period->includes(CarbonImmutable::parse($first)) && ($to === null || $first < $to->format('Y-m-d'));
    }
}
