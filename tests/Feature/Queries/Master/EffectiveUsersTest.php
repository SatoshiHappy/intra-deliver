<?php

namespace Tests\Feature\Queries\Master;

use App\Models\Master\User;
use App\Queries\Master\Api\EffectiveUsers;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 基準日に有効なユーザーの公開の口(docs の 設計/ワークフロー/ルートの深さ/段2の設計への反映.md 3 節: 渡した id のうち d に有効なものだけ、昇順、重ねない、
 * 無い id は入れない)。期間の端(終了日はその日から無効)は共通の規則 5.4 の半開区間。
 */
final class EffectiveUsersTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    public function test_returns_only_the_users_effective_on_the_date_in_ascending_order_without_duplicates(): void
    {
        $leaving = $this->user([['2020-01-01', '2026-01-01']]);
        $joining = $this->user([['2026-01-01', null]]);
        $staying = $this->user();
        $missing = (int) User::query()->max('id') + 1000;

        $this->assertSame([$leaving->id, $staying->id], $this->users()->idsOn([$staying->id, $joining->id, $leaving->id, $staying->id, $missing], self::day('2025-12-31')));
        $this->assertSame([$joining->id, $staying->id], $this->users()->idsOn([$staying->id, $joining->id, $leaving->id], self::day('2026-01-01')));
        $this->assertSame([], $this->users()->idsOn([$missing], self::day('2026-01-01')));
        $this->assertSame([], $this->users()->idsOn([], self::day('2026-01-01')));
    }

    /**
     * 期間 [from, to) のどこかの日に有効な人(docs の 設計/ワークフロー/案件の運用/3aと段2の設計への反映.md の 3 節): to の当日だけ有効な人と from の前日まで
     * 有効な人は外れ、期間の中の 1 日だけ有効な人は入る。to = null は上限なし。無効の行は数えない。昇順・重ねない・無い id は入れない。
     */
    public function test_ids_within_a_half_open_period(): void
    {
        $onlyOnTo = $this->user([['2026-02-01', null]]);
        $untilTheDayBeforeFrom = $this->user([['2020-01-01', '2026-01-01']]);
        $oneDayInside = $this->user([['2026-01-15', '2026-01-16']]);
        $deleted = $this->user([['2026-01-01', null, true]]);
        $missing = (int) User::query()->max('id') + 1000;
        $all = [$oneDayInside->id, $onlyOnTo->id, $untilTheDayBeforeFrom->id, $deleted->id, $oneDayInside->id];
        $within = fn (string $from, ?string $to) => $this->users()->idsWithin(
            [...$all, $missing],
            self::day($from),
            $to === null ? null : self::day($to),
        );

        $this->assertSame([$oneDayInside->id], $within('2026-01-01', '2026-02-01'));
        $this->assertSame([$onlyOnTo->id, $oneDayInside->id], $within('2026-01-01', null));
        $this->assertSame([$untilTheDayBeforeFrom->id], $within('2025-12-31', '2026-01-01'));
        $this->assertSame([], $this->users()->idsWithin([], self::day('2026-01-01'), null));
    }

    private function users(): EffectiveUsers
    {
        return app(EffectiveUsers::class);
    }
}
