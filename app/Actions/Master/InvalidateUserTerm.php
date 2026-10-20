<?php

namespace App\Actions\Master;

use App\Models\Master\UserTerm;
use App\Queries\Master\MembershipQuery;
use App\Queries\Master\UserReferences;
use App\Support\Period;
use DateTimeInterface;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Validation\ValidationException;

/**
 * ユーザーの期間の行を無効にする(docs の 設計/共通マスタ/共通の規則.md 5.3・5.5)。退職は、退職の日で期間を分けて(SplitUserTerm)、後ろの行を無効にする。
 *
 * 無効にしても行は残るので、期間の連続(5.3)は保たれる。止める規則: ユーザーの有効な日を減らすとき、ユーザーを指す期間に従う行の有効な日が
 * はみ出さない(5.5 の 3 行目)。見る行 = Master の所属と、Master の外の行(依存の逆転で登録された UserReferences の実装すべて。いまは
 * Authz のロールの付与)。退職するなら、所属と付与を退職の日で終える行として先に作る・終えておく。
 */
class InvalidateUserTerm
{
    use WritesCommonMaster;

    /**
     * @param  iterable<UserReferences>  $userReferences
     */
    public function __construct(
        private MembershipQuery $memberships,
        #[Tag(UserReferences::TAG)] private iterable $userReferences,
    ) {}

    /**
     * @param  DateTimeInterface  $startDate  無効にする期間の行の開始日
     *
     * @throws ValidationException その日に始まる期間の行が無いとき、ユーザーを指す行がはみ出すとき
     */
    public function execute(int $userId, DateTimeInterface $startDate): UserTerm
    {
        return $this->writeCommonMaster(function () use ($userId, $startDate) {
            $terms = UserTerm::query()->where('user_id', $userId)->get();
            $day = (new Period($startDate))->start;
            $target = $terms->first(fn (UserTerm $term) => $term->start_date->equalTo($day))
                ?? throw ValidationException::withMessages(['date' => ['その日に始まる期間がありません(先に期間を分割してください)']]);
            $remaining = $terms->reject(fn (UserTerm $term) => $term->is($target))
                ->map(fn (UserTerm $term) => $term->effectivePeriod())->filter()->values()->all();
            $this->ensureNothingOverhangs($userId, $remaining);

            $target->is_deleted = true;
            $target->save();

            return $target;
        });
    }

    /**
     * @param  array<int, Period>  $remaining  無効にした後のユーザーの有効な期間
     *
     * @throws ValidationException ユーザーを指す行の有効な日が、残る有効な期間からはみ出すとき
     */
    private function ensureNothingOverhangs(int $userId, array $remaining): void
    {
        if (! $this->allCovered($remaining, $this->memberships->effectivePeriodsOfUser($userId))) {
            throw ValidationException::withMessages(['memberships' => ['無効にする期間と、このユーザーの所属の期間が重複します(先に所属を終了してください)']]);
        }

        foreach ($this->userReferences as $references) {
            if (! $this->allCovered($remaining, $references->effectivePeriodsReferencing($userId))) {
                throw ValidationException::withMessages(['references' => ['無効にする期間と、このユーザーを指す行(ロールの付与など)の期間が重複します(先に終了してください)']]);
            }
        }
    }

    /**
     * @param  array<int, Period>  $remaining
     * @param  array<int, Period>  $periods
     */
    private function allCovered(array $remaining, array $periods): bool
    {
        return collect($periods)->every(fn (Period $period) => Period::covers($remaining, $period));
    }
}
