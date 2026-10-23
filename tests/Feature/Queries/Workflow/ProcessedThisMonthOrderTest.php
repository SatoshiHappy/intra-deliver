<?php

namespace Tests\Feature\Queries\Workflow;

use App\Models\Master\User;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Queries\Workflow\ProcessedThisMonthHomeSection;
use App\Queries\Workflow\WorkflowHomeQuery;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Tests\Support\TransactionalTestCase;

/**
 * ホームの今月の処理済みの表の並び(docs の 設計/ワークフロー/画面.md 7 節): 処理日時の降順、同じ秒は履歴の id の降順。初期データの見本は
 * 案件の番号の順に入るので、処理日時と履歴の id の順が揃わない(設計/ワークフロー/案件の運用/初期データ.md 2 節)。
 */
final class ProcessedThisMonthOrderTest extends TransactionalTestCase
{
    private const DATE = '2026-10-13';

    public function test_rows_are_in_descending_order_of_the_processed_time_then_of_the_history_id(): void
    {
        $user = User::query()->where('code', 'u0002')->sole();
        $ids = WorkflowCaseHistory::query()->where('user_id', $user->id)
            ->whereIn('action', WorkflowHomeQuery::PROCESSED_ACTIONS)
            ->orderBy('id')
            ->limit(3)
            ->pluck('id')
            ->map(fn (mixed $id) => (int) $id)
            ->all();
        $this->assertCount(3, $ids);
        [$oldest, $second, $third] = $ids;
        // 3 行だけを今月に残す: id の最も小さい行を最も新しくし、残りの 2 行は同じ秒にする
        WorkflowCaseHistory::query()->where('user_id', $user->id)->update(['created_at' => '2026-09-01 03:00:00+00']);
        WorkflowCaseHistory::query()->whereKey($oldest)->update(['created_at' => '2026-10-12 05:00:00+00']);
        WorkflowCaseHistory::query()->whereKey([$second, $third])->update(['created_at' => '2026-10-12 04:00:00+00']);

        $date = CarbonImmutable::parse(self::DATE, AccessContext::TIMEZONE);
        $context = new AccessContext($user->id, null, null, $date, AccessContext::LOCALE_CODE);
        $section = app(ProcessedThisMonthHomeSection::class)->section($context);

        $this->assertSame(
            [(string) $oldest, (string) $third, (string) $second],
            array_map(fn (array $row) => $row['code'], $section['rows'] ?? []),
        );
    }
}
