<?php

namespace Tests\Feature\Seeders;

use App\Models\Master\User;
use App\Queries\Notice\NoticeList;
use App\Queries\Workflow\CaseListQuery;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Database\Seeders\SeedingDates;
use Tests\Support\TransactionalTestCase;

/**
 * 初期データを入れた後の利用者ごとのタブ(docs の 設計/ワークフロー/案件の運用/初期データ.md 6 節。期待は同じ文書の 4 節の見本から数えた値)。
 * 今日は流す日に固定する(代理の期間・経過日数・期限が流す日の値で出る。日がたつと変わる値を残さない = 同じ文書の 1.3)。
 * 初期データは DatabaseSeeder が 1 回入れたもの(流し直さない)。
 */
final class WorkflowSeederTabsTest extends TransactionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse(SeedingDates::BASE.' 12:00', AccessContext::TIMEZONE));
    }

    /**
     * 一時保存・未処理・未確認の件数と、未処理(本人の処理対象者と、流す日に有効な承認の代理の代理元の分)・参照・処理済の案件。
     * 流す日の承認の代理 = u0003 → u0009(すべてのフロー)・u0016 → u0003(計画のフロー)。u0010 → u0002 は終わっている(4.1)。
     */
    public function test_each_user_sees_the_tabs_of_the_design(): void
    {
        $users = ['u0001', 'u0002', 'u0003', 'u0009', 'u0010', 'u0016', null];
        $counts = array_map(fn (?string $code) => $this->list($code, 'pending')['counts'], $users);

        $this->assertSame([
            ['draft' => 1, 'pending' => 1, 'unconfirmed' => 0],
            ['draft' => 0, 'pending' => 3, 'unconfirmed' => 0],
            ['draft' => 0, 'pending' => 4, 'unconfirmed' => 0],
            ['draft' => 0, 'pending' => 5, 'unconfirmed' => 0],
            ['draft' => 0, 'pending' => 2, 'unconfirmed' => 4],
            ['draft' => 0, 'pending' => 1, 'unconfirmed' => 0],
            ['draft' => 0, 'pending' => 0, 'unconfirmed' => 0],
        ], $counts);
        $this->assertSame(['WF-000004'], $this->numbersOf('u0001', 'pending'));
        $this->assertSame(['WF-000002', 'WF-000005', 'WF-000015'], $this->numbersOf('u0002', 'pending'));
        $this->assertSame(['WF-000003', 'WF-000009', 'WF-000013', 'WF-000014'], $this->numbersOf('u0003', 'pending'));
        $this->assertSame($this->numbered(3, 10, 13, 14, 16), $this->numbersOf('u0009', 'pending'));
        $this->assertSame(['WF-000009', 'WF-000010'], $this->numbersOf('u0010', 'pending'));
        $this->assertSame(['WF-000009'], $this->numbersOf('u0016', 'pending'));
        $this->assertSame($this->numbered(2, 3, 4, 5, 9, 10, 13, 14, 15, 16), $this->numbersOf('u0010', 'referenced'));
        $this->assertSame($this->numbered(1, 6, 7, 8, 11, 12), $this->numbersOf('u0010', 'referenced', 'completed'));
        $this->assertSame(
            [['WF-000015'], ['WF-000006', 'WF-000011', 'WF-000012']],
            [$this->numbersOf('u0010', 'processed'), $this->numbersOf('u0010', 'processed', 'completed')],
        );
        $tabs = ['draft', 'pending', 'processed', 'referenced'];
        $guestTabs = array_map(fn (string $tab) => $this->numbersOf(null, $tab), $tabs);
        $this->assertSame([[], [], [], []], $guestTabs);
    }

    /**
     * u0009 の未処理の代理元の列: 代理元 u0003 の部長(WF-000003・WF-000013・WF-000014)に高橋 誠、本人の合議(WF-000010)と振替先(WF-000016)は無し。
     * WF-000014 は u0009 が u0003 として一括承認できる(4.3)。
     */
    public function test_the_pending_tab_of_u0009_shows_the_principal_u0003(): void
    {
        $rows = array_column($this->list('u0009', 'pending')['cases']['data'], null, 'number');
        ksort($rows);
        $person = fn (array $person) => [$person['code'], $person['name']];

        $this->assertSame([
            'WF-000003' => [['u0003', '高橋 誠']],
            'WF-000010' => [],
            'WF-000013' => [['u0003', '高橋 誠']],
            'WF-000014' => [['u0003', '高橋 誠']],
            'WF-000016' => [],
        ], array_map(fn (array $row) => array_map($person, $row['principals']), $rows));
        $approval = $rows['WF-000014']['bulkApproval'];
        $this->assertTrue($rows['WF-000014']['can']['bulkApprove']);
        $this->assertSame(['部長', 'u0003'], [$approval['nodeName'], $approval['principal']['code'] ?? null]);
    }

    /**
     * 経過日数・期限(4.2): WF-000003 の部長は経過 9 日・期限 D − 4 で期限超過、WF-000005 の課長は経過 3 日・期限 D(当日)で超過でない、
     * WF-000002(課題)は経過 6 日で期限なし。
     */
    public function test_the_elapsed_days_and_the_due_dates_follow_the_operation_times(): void
    {
        $deadlines = fn (string $code) => array_column(array_map(
            fn (array $row) => [$row['number'], $row['deadline']],
            $this->list($code, 'pending')['cases']['data'],
        ), 1, 0);

        $this->assertSame(
            ['reachedOn' => '2026-10-06', 'elapsedDays' => 9, 'dueOn' => '2026-10-11', 'overdue' => true],
            $deadlines('u0003')['WF-000003'],
        );
        $u0002 = $deadlines('u0002');
        $this->assertSame([
            ['reachedOn' => '2026-10-12', 'elapsedDays' => 3, 'dueOn' => '2026-10-15', 'overdue' => false],
            ['reachedOn' => '2026-10-09', 'elapsedDays' => 6, 'dueOn' => null, 'overdue' => false],
        ], [$u0002['WF-000005'], $u0002['WF-000002']]);
    }

    /**
     * u0010 の未確認(版 2・版 3 の課長が処理済みで未完了の案件。確認が開いた日時 = 課長の承認の時刻)と確認済(WF-000009 の課長・部長。確認の時刻)。
     */
    public function test_u0010_sees_the_unconfirmed_and_the_confirmed_cases(): void
    {
        $confirmations = fn (string $tab) => array_map(fn (array $row) => [
            $row['number'], $row['confirmation']['stepNames'] ?? null, $this->tokyo($row['confirmation']['at'] ?? null),
        ], $this->sortedByNumber($this->list('u0010', $tab)['cases']['data']));

        $this->assertSame([
            ['WF-000003', ['課長の承認'], '2026-10-06 10:30'],
            ['WF-000010', ['課長'], '2026-10-12 10:00'],
            ['WF-000013', ['課長'], '2026-10-06 10:30'],
            ['WF-000014', ['課長'], '2026-10-13 10:00'],
        ], $confirmations('unconfirmed'));
        $this->assertSame([['WF-000009', ['課長', '部長'], '2026-10-14 10:20']], $confirmations('confirmed'));
    }

    /**
     * u0012(WF-000014 の連絡の受け手。閲覧できない)の通知の連絡の行は移り先が無く「この申請は閲覧できません。」。
     */
    public function test_the_contact_notice_of_u0012_has_no_destination(): void
    {
        $rows = array_values(array_filter(
            app(NoticeList::class)->latest($this->contextOf('u0012')),
            fn (array $row) => $row['type'] === 'workflow.contact',
        ));

        $destinations = array_map(fn (array $row) => [$row['url'], $row['unavailableText']], $rows);
        $this->assertSame([[null, 'この申請は閲覧できません。']], $destinations);
    }

    /**
     * @return array{query: array{tab: string, scope: string, q: string},
     *     counts: array{draft: int, pending: int, unconfirmed: int},
     *     cases: array{data: list<array<string, mixed>>, currentPage: int, lastPage: int, total: int}}
     */
    private function list(?string $userCode, string $tab, string $scope = 'active'): array
    {
        return app(CaseListQuery::class)->list($this->contextOf($userCode), $tab, $scope, '1');
    }

    /**
     * タブの案件の番号(番号の順)。
     *
     * @return list<string|null>
     */
    private function numbersOf(?string $userCode, string $tab, string $scope = 'active'): array
    {
        $numbers = array_column($this->list($userCode, $tab, $scope)['cases']['data'], 'number');
        sort($numbers);

        return $numbers;
    }

    /**
     * 番号(WF-00000N の N)の並びの表示の形。
     *
     * @return list<string>
     */
    private function numbered(int ...$numbers): array
    {
        return array_values(array_map(fn (int $number) => sprintf('WF-%06d', $number), $numbers));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sortedByNumber(array $rows): array
    {
        usort($rows, fn (array $a, array $b) => strcmp((string) $a['number'], (string) $b['number']));

        return $rows;
    }

    private function tokyo(?string $at): ?string
    {
        return $at === null ? null
            : CarbonImmutable::parse($at)->setTimezone(AccessContext::TIMEZONE)->format('Y-m-d H:i');
    }

    private function contextOf(?string $userCode): AccessContext
    {
        $userId = $userCode === null ? null : User::query()->where('code', $userCode)->sole()->id;

        $today = CarbonImmutable::today(AccessContext::TIMEZONE);

        return new AccessContext($userId, null, null, $today, AccessContext::LOCALE_CODE);
    }
}
