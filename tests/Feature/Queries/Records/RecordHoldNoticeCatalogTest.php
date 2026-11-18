<?php

namespace Tests\Feature\Queries\Records;

use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordHoldLink;
use App\Queries\Records\Api\RecordHoldState;
use App\Queries\Records\RecordHoldNoticeCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use LogicException;
use RuntimeException;
use Tests\Support\FixedRecordHoldNotices;
use Tests\Support\RegistersRecordHoldNotices;
use Tests\Support\TransactionalTestCase;

/**
 * 持ち主の案内を束ねる 1 か所(docs の 設計/ワークフロー/業務データとの接続/公開の口/表示と関係の口.md の 7.3 の束ね方と失敗の欄): 登録の順に聞き、
 * 実装の例外・渡していない版・決まった値の外の札の色は報告して、その実装の分を案内の無い形にする。
 */
final class RecordHoldNoticeCatalogTest extends TransactionalTestCase
{
    use RegistersRecordHoldNotices;

    private const HOLDER = 'test-holder:1';

    public function test_no_implementation_gives_no_notice(): void
    {
        $catalog = app(RecordHoldNoticeCatalog::class);

        $this->assertNull($catalog->stateOf($this->requester(), self::HOLDER, $this->date()));
        $this->assertSame([], $catalog->revisionLinks(1, [10]));
        $this->assertSame([], $catalog->attachmentDisableNotices(1));
    }

    public function test_state_is_the_first_non_null_in_order_and_gets_the_requester_and_the_date(): void
    {
        $skipped = $this->registerRecordHoldNotices(FixedRecordHoldNotices::of());
        $this->registerRecordHoldNotices(FixedRecordHoldNotices::of(state: fn () => $this->state('pending', '一つ目')));
        $this->registerRecordHoldNotices(FixedRecordHoldNotices::of(state: fn () => $this->state('settled', '二つ目')));
        $requester = $this->requester();
        $date = $this->date();

        $state = app(RecordHoldNoticeCatalog::class)->stateOf($requester, self::HOLDER, $date);

        $this->assertSame('一つ目', $state?->status);
        $this->assertSame([[$requester, self::HOLDER, $date]], $skipped->stateCalls);
    }

    public function test_an_exception_of_an_implementation_is_reported_and_the_next_one_answers(): void
    {
        Exceptions::fake();
        $this->registerRecordHoldNotices(
            FixedRecordHoldNotices::of(state: fn () => throw new RuntimeException('test-throw')),
        );
        $this->registerRecordHoldNotices(FixedRecordHoldNotices::of(state: fn () => $this->state('pending', '次')));

        $state = app(RecordHoldNoticeCatalog::class)->stateOf($this->requester(), self::HOLDER, $this->date());

        $this->assertSame('次', $state?->status);
        Exceptions::assertReported(fn (LogicException $e) => str_contains($e->getMessage(), 'stateOf() が例外を投げた')
            && $e->getPrevious()?->getMessage() === 'test-throw');
    }

    public function test_a_tone_outside_the_fixed_values_is_reported_and_the_next_one_answers(): void
    {
        Exceptions::fake();
        $this->registerRecordHoldNotices(FixedRecordHoldNotices::of(state: fn () => $this->state('unknown', '色の外')));
        $next = $this->registerRecordHoldNotices(
            FixedRecordHoldNotices::of(state: fn () => $this->state('pending', '次')),
        );

        $state = app(RecordHoldNoticeCatalog::class)->stateOf($this->requester(), self::HOLDER, $this->date());

        $this->assertSame('次', $state?->status);
        $this->assertCount(1, $next->stateCalls);
        Exceptions::assertReported(fn (LogicException $e) => str_contains($e->getMessage(), '札の色 unknown'));
    }

    public function test_revision_links_keep_only_the_passed_revisions_and_the_first_link_of_each(): void
    {
        Exceptions::fake();
        $first = $this->registerRecordHoldNotices(FixedRecordHoldNotices::of(revisions: fn () => [
            10 => new RecordHoldLink('WF-000001', '/a'),
            99 => new RecordHoldLink('WF-000099', '/z'),
        ]));
        $this->registerRecordHoldNotices(FixedRecordHoldNotices::of(revisions: fn () => [
            10 => new RecordHoldLink('WF-000002', '/b'),
            11 => new RecordHoldLink('WF-000003', '/c'),
        ]));

        $links = app(RecordHoldNoticeCatalog::class)->revisionLinks(7, [10, 11]);

        $this->assertSame(['WF-000001', 'WF-000003'], [$links[10]->label, $links[11]->label]);
        $this->assertSame([10, 11], array_keys($links));
        $this->assertSame([[7, [10, 11]]], $first->revisionCalls);
        Exceptions::assertReported(fn (LogicException $e) => str_contains($e->getMessage(), '渡していない版 99'));
    }

    public function test_revision_links_are_not_asked_without_revisions_and_an_exception_gives_none(): void
    {
        Exceptions::fake();
        $notices = $this->registerRecordHoldNotices(
            FixedRecordHoldNotices::of(revisions: fn () => throw new RuntimeException('test-throw')),
        );
        $catalog = app(RecordHoldNoticeCatalog::class);

        $this->assertSame([], $catalog->revisionLinks(7, []));
        $this->assertSame([], $notices->revisionCalls);
        $this->assertSame([], $catalog->revisionLinks(7, [10]));
        Exceptions::assertReported(
            fn (LogicException $e) => str_contains($e->getMessage(), 'revisionLinks() が例外を投げた'),
        );
    }

    public function test_attachment_notices_are_every_non_null_text_in_the_order_of_registration(): void
    {
        Exceptions::fake();
        $this->registerRecordHoldNotices(FixedRecordHoldNotices::of(attachment: fn () => '一つ目の文'));
        $this->registerRecordHoldNotices(FixedRecordHoldNotices::of());
        $this->registerRecordHoldNotices(
            FixedRecordHoldNotices::of(attachment: fn () => throw new RuntimeException('test-throw')),
        );
        $this->registerRecordHoldNotices(FixedRecordHoldNotices::of(attachment: fn () => '二つ目の文'));

        $this->assertSame(['一つ目の文', '二つ目の文'], app(RecordHoldNoticeCatalog::class)->attachmentDisableNotices(3));
        Exceptions::assertReported(
            fn (LogicException $e) => str_contains($e->getMessage(), 'attachmentDisableNotice() が例外を投げた'),
        );
    }

    private function state(string $tone, string $status): RecordHoldState
    {
        return new RecordHoldState(new RecordHoldLink('WF-000001', '/workflow/cases/1'), $status, $tone, null);
    }

    private function requester(): Requester
    {
        return new Requester(1, null);
    }

    private function date(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-18');
    }
}
