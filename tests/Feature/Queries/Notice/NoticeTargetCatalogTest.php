<?php

namespace Tests\Feature\Queries\Notice;

use App\Queries\Notice\Api\NoticeTargetLink;
use App\Queries\Notice\NoticeTargetCatalog;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use LogicException;
use Tests\Support\FixedNoticeTargets;
use Tests\Support\TransactionalTestCase;

/**
 * 通知の対象の移り先を束ねる 1 か所(docs の 設計/ワークフロー/案件の運用/通知.md の 1 節と 5 節): 対象の種類ごとに実装を 1 回だけ呼ぶ。
 * 同じ対象の種類を 2 つの実装が答えると例外。実装の無い対象の種類は共通の 1 行にし、1 回の解決につき 1 回だけ記録する。
 */
final class NoticeTargetCatalogTest extends TransactionalTestCase
{
    public function test_asks_each_implementation_once_per_target_type_with_all_its_ids(): void
    {
        $link = new NoticeTargetLink('/cases/3', null);
        $cases = new FixedNoticeTargets(['test-case', 'test-proxy'], [3 => $link], '見えない。');
        $records = new FixedNoticeTargets(['test-record'], [], 'このレコードは閲覧できません。');
        $catalog = new NoticeTargetCatalog([$cases, $records]);

        $targetIds = ['test-case' => [3, 4], 'test-proxy' => [5], 'test-record' => [6]];
        $resolved = $catalog->resolve($targetIds, $this->context());

        $this->assertSame([['test-case', [3, 4]], ['test-proxy', [5]]], $cases->calls);
        $this->assertSame([['test-record', [6]]], $records->calls);
        $this->assertEquals([
            'test-case' => ['links' => [3 => $link], 'unavailableText' => '見えない。'],
            'test-proxy' => ['links' => [], 'unavailableText' => '見えない。'],
            'test-record' => ['links' => [], 'unavailableText' => 'このレコードは閲覧できません。'],
        ], $resolved);
    }

    public function test_throws_when_two_implementations_answer_the_same_target_type(): void
    {
        $catalog = new NoticeTargetCatalog([
            new FixedNoticeTargets(['test-case'], [], 'a'),
            new FixedNoticeTargets(['test-record', 'test-case'], [], 'b'),
        ]);

        $this->expectException(LogicException::class);

        $catalog->resolve(['test-record' => [1]], $this->context());
    }

    public function test_gives_the_common_line_to_unanswered_types_and_reports_once_per_resolution(): void
    {
        Exceptions::fake();
        $catalog = new NoticeTargetCatalog([new FixedNoticeTargets(['test-case'], [], '見えない。')]);

        $targetIds = ['test-gone' => [1, 2], 'test-case' => [3], 'test-lost' => [4]];
        $resolved = $catalog->resolve($targetIds, $this->context());

        $this->assertSame(['links' => [], 'unavailableText' => 'この通知の対象は表示できません。'], $resolved['test-gone']);
        $this->assertSame(['links' => [], 'unavailableText' => 'この通知の対象は表示できません。'], $resolved['test-lost']);
        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(
            fn (LogicException $reported) => str_contains($reported->getMessage(), 'test-gone(2 件)、test-lost(1 件)'),
        );

        $catalog->resolve(['test-gone' => [1]], $this->context());

        Exceptions::assertReportedCount(2);
    }

    public function test_reports_nothing_when_every_target_type_has_an_implementation(): void
    {
        Exceptions::fake();
        $catalog = new NoticeTargetCatalog([new FixedNoticeTargets(['test-case'], [], '見えない。')]);

        $catalog->resolve(['test-case' => [1]], $this->context());
        $catalog->resolve([], $this->context());

        Exceptions::assertNothingReported();
    }

    private function context(): AccessContext
    {
        $today = CarbonImmutable::today(AccessContext::TIMEZONE);

        return new AccessContext(null, null, null, $today, AccessContext::LOCALE_CODE);
    }
}
