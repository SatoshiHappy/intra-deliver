<?php

namespace Tests\Feature\Seeders;

use App\Models\Notice\Notice;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseConfirmation;
use App\Models\Workflow\WorkflowCaseConfirmTarget;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowProxy;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Seeders\SeedingClock;
use Database\Seeders\SeedingDates;
use RuntimeException;
use Tests\Support\TransactionalTestCase;

/**
 * 見本の処理の日時(docs の 設計/ワークフロー/案件の運用/初期データ.md 1.2・4 節): 操作ごとに時計を合わせて入れた見本の、履歴・到達・確認・代理の登録・
 * 通知の時刻が表の「D − n 日の hh:mm」(Feature テストの流す日 D = 基準の日)。時計を合わせる 1 か所が、見本の後(例外のときも)時計を戻すこと。
 * 期待は表の書き方のまま「D-n hh:mm」で書き、基準の日から日付にする。初期データは DatabaseSeeder が 1 回入れたもの(流し直さない)。
 */
final class WorkflowSeederSampleTimesTest extends TransactionalTestCase
{
    /**
     * 4.2・4.3: 案件ごとの履歴の時刻(操作の順)と、一時保存を作った時刻。
     */
    public function test_the_histories_have_the_operation_times_of_the_design(): void
    {
        $histories = WorkflowCaseHistory::query()->with('workflowCase')->orderBy('id')->get()
            ->groupBy(fn (WorkflowCaseHistory $history) => (int) $history->workflowCase?->case_number)
            ->map(fn ($rows) => $rows->map(fn (WorkflowCaseHistory $row) => $this->tokyo($row->created_at))->values())
            ->sortKeys()->toArray();

        $this->assertSame(array_map(fn (array $times) => array_map($this->at(...), $times), [
            1 => ['D-40 09:00', 'D-39 10:00', 'D-38 11:00'],
            2 => ['D-6 09:30'],
            3 => ['D-10 09:00', 'D-9 10:30'],
            4 => ['D-5 09:00', 'D-4 14:00'],
            5 => ['D-12 09:00', 'D-11 15:00', 'D-3 10:00'],
            6 => ['D-30 09:00', 'D-29 10:00'],
            7 => ['D-25 09:00', 'D-24 10:00'],
            8 => ['D-20 09:00', 'D-19 10:00', 'D-18 11:00'],
            9 => ['D-8 09:00', 'D-7 10:00', 'D-6 11:00', 'D-5 13:00'],
            10 => ['D-4 09:00', 'D-3 10:00'],
            11 => ['D-15 09:00', 'D-14 10:00', 'D-13 11:00', 'D-12 13:00', 'D-12 15:00'],
            12 => ['D-35 09:00', 'D-34 10:00', 'D-33 11:00', 'D-33 14:00', 'D-32 10:00'],
            13 => ['D-9 09:20', 'D-9 10:30', 'D-1 16:20'],
            14 => ['D-3 09:00', 'D-2 10:00'],
            15 => ['D-5 09:00'],
            16 => ['D-6 09:00', 'D-5 15:20'],
        ]), $histories);
        $draft = WorkflowCase::query()->where('state', 'draft')->sole();
        $this->assertSame($this->at('D-1 17:00'), $this->tokyo($draft->created_at));
    }

    /**
     * 現在のノードの到達日時(到達させた操作の時刻。担当変更・保留では変わらない = 設計/ワークフロー/案件の運用/案件の操作/README.md の 1.4 節)と、
     * 確認対象者の行(確認が開いた操作の時刻)・確認の行(確認の時刻)・代理の行(登録の時刻)。
     */
    public function test_the_reached_the_confirmed_and_the_registered_times_follow_the_operations(): void
    {
        $reached = WorkflowCaseNode::query()->with('workflowCase')->whereIn('state', ['waiting', 'reapply', 'hold'])
            ->orderBy('id')->get()
            ->map(fn (WorkflowCaseNode $node) => [$node->workflowCase?->case_number, $this->tokyo($node->reached_at)])
            ->all();
        $opened = WorkflowCaseConfirmTarget::query()->with('workflowCase')->orderBy('id')->get()
            ->map(fn (WorkflowCaseConfirmTarget $t) => [$t->workflowCase?->case_number, $this->tokyo($t->created_at)])
            ->all();

        $this->assertSame($this->numbered([
            [2, 'D-6 09:30'], [3, 'D-9 10:30'], [4, 'D-4 14:00'], [5, 'D-3 10:00'], [9, 'D-6 11:00'], [9, 'D-6 11:00'],
            [10, 'D-3 10:00'], [10, 'D-3 10:00'], [13, 'D-9 10:30'], [14, 'D-2 10:00'], [15, 'D-5 09:00'],
            [16, 'D-6 09:00'],
        ]), $reached);
        $this->assertSame($this->numbered([
            [1, 'D-39 10:00'], [3, 'D-9 10:30'], [9, 'D-7 10:00'], [9, 'D-6 11:00'], [10, 'D-3 10:00'],
            [11, 'D-14 10:00'], [11, 'D-13 11:00'], [12, 'D-34 10:00'], [13, 'D-9 10:30'], [14, 'D-2 10:00'],
        ]), $opened);
        $this->assertSame(
            array_map($this->at(...), ['D-1 10:20', 'D-1 10:20']),
            WorkflowCaseConfirmation::query()->orderBy('id')->get()
                ->map(fn (WorkflowCaseConfirmation $row) => $this->tokyo($row->created_at))->all(),
        );
        $this->assertSame(
            array_map($this->at(...), ['D-46 09:00', 'D-16 10:00', 'D-7 11:00', 'D-2 09:05']),
            WorkflowProxy::query()->orderBy('id')->get()
                ->map(fn (WorkflowProxy $row) => $this->tokyo($row->created_at))->all(),
        );
    }

    /**
     * 案件の通知は、それを送った操作と同じ時刻(業務処理が同じ時計で書く)。どの通知の時刻も、その案件の履歴のどれかの時刻。
     */
    public function test_the_case_notices_are_written_at_the_times_of_their_operations(): void
    {
        $historyTimes = WorkflowCaseHistory::query()->get()->groupBy('workflow_case_id')
            ->map(fn ($rows) => $rows->map(fn (WorkflowCaseHistory $history) => $this->tokyo($history->created_at)));
        $notices = Notice::query()->where('target_type', 'workflow-case')->orderBy('id')->get();

        $this->assertNotEmpty($notices);

        foreach ($notices as $notice) {
            $times = $historyTimes->get($notice->target_id)?->all() ?? [];
            $this->assertContains($this->tokyo($notice->created_at), $times, "通知 {$notice->id}");
        }
    }

    /**
     * 時計を合わせる 1 か所: 合わせた時刻 = 基準の日に書いた日時を流す日からの相対にしたもの、返す日 = その日の 0 時。見本を入れた後は合わせる前の時計に戻り、
     * 途中の例外のときも戻って例外はそのまま伝わる。
     */
    public function test_the_clock_is_restored_after_the_samples_even_on_an_exception(): void
    {
        config(['seeding.date' => '2027-11-19']);
        $day = null;
        SeedingClock::restoring(function () use (&$day) {
            $day = SeedingClock::set('2026-10-05 09:00');
            $this->assertSame('2027-11-09 09:00', $this->tokyo(CarbonImmutable::now()));
        });

        $this->assertSame('2027-11-09 00:00:00 Asia/Tokyo', $day?->format('Y-m-d H:i:s e'));
        $this->assertFalse(CarbonImmutable::hasTestNow());

        $before = CarbonImmutable::parse('2026-01-02 03:04:05', 'UTC');
        CarbonImmutable::setTestNow($before);
        $this->assertThrows(fn () => SeedingClock::restoring(function () {
            SeedingClock::set('2026-10-05 09:00');

            throw new RuntimeException('見本の途中の失敗');
        }), RuntimeException::class, '見本の途中の失敗');
        $this->assertTrue($before->equalTo(CarbonImmutable::now()));
    }

    /**
     * 流す日の設定が無いとき、時計を合わせる前の今日を流す日に固める(合わせた後の今日を流す日と読まない)。2 回目に合わせた日は 1 回目の翌日のまま。
     */
    public function test_the_seeding_day_is_fixed_before_the_clock_moves(): void
    {
        config(['seeding.date' => null]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00', AccessContext::TIMEZONE));
        $days = [];
        SeedingClock::restoring(function () use (&$days) {
            $days[] = SeedingClock::set('2026-10-05 09:00')->format('Y-m-d');
            $days[] = SeedingClock::set('2026-10-06 10:00')->format('Y-m-d');
        });

        $this->assertSame(['2026-10-10', '2026-10-11'], $days);
    }

    /**
     * 表の「D-n hh:mm」を、流す日 = 基準の日での Asia/Tokyo の「Y-m-d H:i」にする。
     */
    private function at(string $written): string
    {
        [$day, $time] = explode(' ', $written);

        return CarbonImmutable::parse(SeedingDates::BASE)->subDays((int) substr($day, 2))->format('Y-m-d')." {$time}";
    }

    /**
     * @param  list<array{int, string}>  $rows  [案件番号, 「D-n hh:mm」]
     * @return list<array{int, string}>
     */
    private function numbered(array $rows): array
    {
        return array_map(fn (array $row) => [$row[0], $this->at($row[1])], $rows);
    }

    private function tokyo(?CarbonInterface $time): ?string
    {
        return $time?->setTimezone(AccessContext::TIMEZONE)->format('Y-m-d H:i');
    }
}
