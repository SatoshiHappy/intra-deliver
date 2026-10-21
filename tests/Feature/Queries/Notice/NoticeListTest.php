<?php

namespace Tests\Feature\Queries\Notice;

use App\Models\Master\User;
use App\Models\Notice\Notice;
use App\Queries\Notice\Api\NoticeTargetLink;
use App\Queries\Notice\NoticeList;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use LogicException;
use Tests\Support\RegistersNoticeTargets;
use Tests\Support\StartsWithoutSeededNotices;
use Tests\Support\TransactionalTestCase;

/**
 * 自分宛の通知の読み取り(docs の 設計/ワークフロー/案件の運用/通知.md の 5〜7 節、形は 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.5):
 * 未読の数・鈴の 10 件・一覧(絞り込みと 20 件ずつ)・行の移り先(テストの中で登録する偽の実装で)・並び・ゲスト。
 * 今日を 2026-10-10 に固定する(起こした人の名前は今日の名前)。
 */
final class NoticeListTest extends TransactionalTestCase
{
    use RegistersNoticeTargets;
    use StartsWithoutSeededNotices;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
        $this->userId = $this->userIdOf('u0001');
    }

    public function test_the_summary_counts_own_unread_notices_beyond_99_and_is_null_for_a_guest(): void
    {
        Notice::factory()->count(120)->create(['user_id' => $this->userId]);
        Notice::factory()->count(2)->create(['user_id' => $this->userId, 'read_at' => now()]);
        Notice::factory()->create(['user_id' => $this->userIdOf('u0002')]);

        $this->assertSame(['unreadCount' => 120], $this->list()->summary($this->contextOf($this->userId)));
        $this->assertNull($this->list()->summary($this->contextOf(null)));
    }

    public function test_a_row_without_a_target_has_no_destination_and_shows_the_actor_by_todays_name(): void
    {
        $notice = Notice::factory()->createOne([
            'user_id' => $this->userId,
            'type' => 'workflow.contact',
            'actor_user_id' => $this->userIdOf('u0009'),
            'params' => ['case_name' => '出張申請', 'message' => null],
        ]);

        $row = $this->list()->latest($this->contextOf($this->userId))[0];
        // params は JSON の文字列で比べる(キーの順と、空のときの {} を確かめるため。配列の比べでは [] と区別できない)
        $this->assertSame('{"message":null,"case_name":"出張申請"}', json_encode($row['params'], JSON_UNESCAPED_UNICODE));
        unset($row['params']);
        $this->assertSame([
            'id' => $notice->id,
            'type' => 'workflow.contact',
            'actor' => ['name' => '吉田 達也', 'code' => 'u0009'],
            'createdAt' => '2026-10-10T03:00:00+00:00',
            'readAt' => null,
            'url' => null,
            'targetLabel' => null,
            'unavailableText' => null,
        ], $row);
    }

    public function test_empty_params_stay_a_json_object(): void
    {
        Notice::factory()->createOne(['user_id' => $this->userId]);

        $row = $this->list()->latest($this->contextOf($this->userId))[0];

        $this->assertSame('{}', json_encode($row['params']));
    }

    public function test_a_row_without_an_actor_has_no_actor(): void
    {
        Notice::factory()->createOne(['user_id' => $this->userId]);

        $this->assertNull($this->list()->latest($this->contextOf($this->userId))[0]['actor']);
    }

    public function test_a_visible_target_gives_its_destination_and_its_label_only_when_it_has_one(): void
    {
        $this->registerNoticeTargets([
            1 => new NoticeTargetLink('/test/1', 'レコード 1'),
            2 => new NoticeTargetLink('/test/2', null),
        ]);
        $labelled = $this->targetNotice(1);
        $unlabelled = $this->targetNotice(2);

        $rows = $this->rowsById($this->list()->latest($this->contextOf($this->userId)));

        $this->assertSame(['/test/1', 'レコード 1', null], $this->destinationOf($rows[$labelled->id]));
        $this->assertSame(['/test/2', null, null], $this->destinationOf($rows[$unlabelled->id]));
    }

    public function test_an_invisible_target_gives_no_destination_and_the_line_of_its_implementation(): void
    {
        $this->registerNoticeTargets([1 => new NoticeTargetLink('/test/1', null)]);
        $invisible = $this->targetNotice(9);

        $rows = $this->rowsById($this->list()->latest($this->contextOf($this->userId)));

        $this->assertSame([null, null, 'このテストの対象は閲覧できません。'], $this->destinationOf($rows[$invisible->id]));
    }

    public function test_a_target_type_without_an_implementation_gives_the_common_line_and_is_reported_once(): void
    {
        Exceptions::fake();
        $this->targetNotice(1, 'test-gone');
        $this->targetNotice(2, 'test-gone');

        $rows = $this->list()->latest($this->contextOf($this->userId));

        $common = [null, null, 'この通知の対象は表示できません。'];

        $this->assertSame([$common, $common], array_map($this->destinationOf(...), $rows));
        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(LogicException::class);
    }

    public function test_the_latest_ten_judge_each_target_type_with_one_call(): void
    {
        $cases = $this->registerNoticeTargets([], ['test-case', 'test-proxy']);
        $records = $this->registerNoticeTargets([], ['test-record']);

        foreach ([1, 2, 3, 4, 1] as $id) {
            $this->targetNotice($id, 'test-case');
        }

        $this->targetNotice(7, 'test-proxy');
        $this->targetNotice(5, 'test-record');
        $this->targetNotice(6, 'test-record');
        Notice::factory()->count(2)->create(['user_id' => $this->userId]);
        $this->targetNotice(8, 'test-record', '2026-10-01 00:00:00+00');

        $this->assertCount(10, $this->list()->latest($this->contextOf($this->userId)));
        $this->assertSame([['test-case', [1, 2, 3, 4]], ['test-proxy', [7]]], $this->sortedCalls($cases->calls));
        $this->assertSame([['test-record', [5, 6]]], $this->sortedCalls($records->calls));
    }

    public function test_orders_by_created_at_descending_then_by_id_descending(): void
    {
        $later = $this->ownNotice(['created_at' => '2026-10-09 10:00:00+00']);
        $earlier = $this->ownNotice(['created_at' => '2026-10-08 10:00:00+00']);
        $sameTimeFirst = $this->ownNotice(['created_at' => '2026-10-07 10:00:00+00']);
        $sameTimeSecond = $this->ownNotice(['created_at' => '2026-10-07 10:00:00+00']);
        $newest = $this->ownNotice(['created_at' => '2026-10-09 12:00:00+00']);
        $expected = [$newest->id, $later->id, $earlier->id, $sameTimeSecond->id, $sameTimeFirst->id];
        $context = $this->contextOf($this->userId);

        $this->assertSame($expected, array_column($this->list()->latest($context), 'id'));
        $this->assertSame($expected, array_column($this->list()->page($context, null, null)['notices']['data'], 'id'));
    }

    public function test_the_page_filters_all_or_unread_and_counts_the_unread(): void
    {
        $unread = Notice::factory()->createOne(['user_id' => $this->userId]);
        $read = Notice::factory()->createOne(['user_id' => $this->userId, 'read_at' => now()]);
        Notice::factory()->createOne(['user_id' => $this->userIdOf('u0002')]);
        $context = $this->contextOf($this->userId);

        $all = $this->list()->page($context, 'all', null);
        $unreadOnly = $this->list()->page($context, 'unread', null);
        $unknown = $this->list()->page($context, 'other', null);

        $this->assertSame(['filter' => 'all'], $all['query']);
        $this->assertSame([$read->id, $unread->id], array_column($all['notices']['data'], 'id'));
        $this->assertSame(['filter' => 'unread'], $unreadOnly['query']);
        $this->assertSame([$unread->id], array_column($unreadOnly['notices']['data'], 'id'));
        $this->assertSame(['unread' => 1], $unreadOnly['counts']);
        $this->assertSame(['filter' => 'all'], $unknown['query']);
    }

    public function test_the_page_has_twenty_rows_per_page(): void
    {
        Notice::factory()->count(45)->create(['user_id' => $this->userId]);

        $context = $this->contextOf($this->userId);

        $notices = $this->list()->page($context, 'all', '3')['notices'];

        $this->assertCount(5, $notices['data']);
        $this->assertSame([3, 3, 45], [$notices['currentPage'], $notices['lastPage'], $notices['total']]);
        $this->assertSame(1, $this->list()->page($context, 'all', 'x')['notices']['currentPage']);
    }

    public function test_a_guest_gets_an_empty_page_and_no_latest_rows(): void
    {
        Notice::factory()->count(2)->create(['user_id' => $this->userId]);

        $this->assertSame([
            'query' => ['filter' => 'all'],
            'counts' => ['unread' => 0],
            'notices' => ['data' => [], 'currentPage' => 1, 'lastPage' => 1, 'total' => 0],
        ], $this->list()->page($this->contextOf(null), null, null));
        $this->assertSame([], $this->list()->latest($this->contextOf(null)));
    }

    public function test_the_destination_judges_an_own_notice_again(): void
    {
        $this->registerNoticeTargets([1 => new NoticeTargetLink('/test/1', 'レコード 1')]);
        $context = $this->contextOf($this->userId);

        $visible = $this->targetNotice(1)->id;
        $invisible = $this->targetNotice(2)->id;
        $withoutTarget = $this->ownNotice()->id;

        $destinations = array_map(
            fn (int $noticeId) => $this->list()->destination($context, $noticeId),
            [$visible, $invisible, $withoutTarget],
        );

        $this->assertSame([
            ['url' => '/test/1', 'unavailableText' => null],
            ['url' => null, 'unavailableText' => self::TEST_UNAVAILABLE_TEXT],
            ['url' => null, 'unavailableText' => null],
        ], $destinations);
    }

    private function list(): NoticeList
    {
        return app(NoticeList::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function ownNotice(array $attributes = []): Notice
    {
        return Notice::factory()->createOne(['user_id' => $this->userId, ...$attributes]);
    }

    private function targetNotice(int $id, string $type = self::TEST_TARGET_TYPE, ?string $at = null): Notice
    {
        return $this->ownNotice(['target_type' => $type, 'target_id' => $id, 'created_at' => $at ?? now()]);
    }

    /**
     * @param  list<array{id: int, url: string|null, targetLabel: string|null, unavailableText: string|null}>  $rows
     * @return array<int, array{id: int, url: string|null, targetLabel: string|null, unavailableText: string|null}>
     */
    private function rowsById(array $rows): array
    {
        return array_column($rows, null, 'id');
    }

    /**
     * @param  array{url: string|null, targetLabel: string|null, unavailableText: string|null}  $row
     * @return array{string|null, string|null, string|null}
     */
    private function destinationOf(array $row): array
    {
        return [$row['url'], $row['targetLabel'], $row['unavailableText']];
    }

    /**
     * 呼び出しを対象の種類の順に並べ、id を昇順にする(呼ぶ順と id の順は行の並びで決まり、判定の回数の検査には関わらない)。
     *
     * @param  list<array{string, list<int>}>  $calls
     * @return list<array{string, list<int>}>
     */
    private function sortedCalls(array $calls): array
    {
        $sorted = array_map(function (array $call): array {
            sort($call[1]);

            return $call;
        }, $calls);
        usort($sorted, fn (array $left, array $right): int => strcmp($left[0], $right[0]));

        return $sorted;
    }

    private function userIdOf(string $code): int
    {
        return User::query()->where('code', $code)->sole()->id;
    }

    private function contextOf(?int $userId): AccessContext
    {
        $today = CarbonImmutable::today(AccessContext::TIMEZONE);

        return new AccessContext($userId, null, null, $today, AccessContext::LOCALE_CODE);
    }
}
