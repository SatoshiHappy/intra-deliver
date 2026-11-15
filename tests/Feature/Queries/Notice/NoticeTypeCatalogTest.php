<?php

namespace Tests\Feature\Queries\Notice;

use App\Enums\Meeting\MeetingNoticeType;
use App\Enums\Records\RecordNoticeType;
use App\Enums\Workflow\WorkflowNoticeType;
use App\Queries\Notice\Api\NoticeTypeEntry;
use App\Queries\Notice\NoticeTypeCatalog;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RegistersNoticeTypes;
use Tests\Support\TransactionalTestCase;

/**
 * 通知の種類の一覧(docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/種類と作る規則.md の 1 節、検査 = ルートと文言と検査.md の 8 節):
 * 起動が登録した 3 つの実装の答えを登録の順に束ね、各送り手の列挙の全部が入り、切り替えられないのは連絡だけ。
 * 束ねる時の例外 4 つは、テストの中だけの実装を起動の実装の後ろに登録して通す。束ねるのは 1 要求に 1 回。
 */
final class NoticeTypeCatalogTest extends TransactionalTestCase
{
    use RegistersNoticeTypes;

    /**
     * @return array<string, array{list<list<NoticeTypeEntry>>, string}>
     */
    public static function brokenAnswers(): array
    {
        $a = new NoticeTypeEntry('test.a', true);
        $b = new NoticeTypeEntry('test.b', true);

        return [
            '書式の誤り' => [[[new NoticeTypeEntry('Test.a', true)]], '「モジュール.種類」の書式でない'],
            '1 つの答えに同じ値が 2 度' => [[[$a, $a]], 'test.a が一覧に 2 度ある'],
            '実装の間で同じ値が 2 度' => [[[$a], [new NoticeTypeEntry('test.a', false)]], 'test.a が一覧に 2 度ある'],
            '既にある値をもう 1 つの実装が答える' => [[[new NoticeTypeEntry('records.comment', true)]], 'records.comment が一覧に 2 度ある'],
            '1 つの答えに接頭辞が 2 つ' => [[[$a, new NoticeTypeEntry('other.b', true)]], '接頭辞を 2 つ以上答えている'],
            '2 つの実装が同じ接頭辞' => [[[$a], [$b]], '接頭辞 test を 2 つの実装が答えている'],
        ];
    }

    public function test_the_list_has_every_case_of_the_three_enums_in_the_order_of_the_table(): void
    {
        $expected = [
            'workflow.process_request', 'workflow.transfer', 'workflow.retarget', 'workflow.result',
            'workflow.confirm_request', 'workflow.proxy', 'workflow.contact',
            'records.comment',
            'meeting.minutes_created', 'meeting.minutes_regenerated',
            'meeting.transcription_failed', 'meeting.minutes_failed',
        ];
        $enums = [...WorkflowNoticeType::cases(), ...RecordNoticeType::cases(), ...MeetingNoticeType::cases()];

        $types = array_map(fn (NoticeTypeEntry $entry): string => $entry->type, app(NoticeTypeCatalog::class)->all());

        $this->assertSame($expected, $types);
        $this->assertSame(
            $expected,
            array_map(fn (WorkflowNoticeType|RecordNoticeType|MeetingNoticeType $case): string => $case->value, $enums),
        );
    }

    public function test_only_the_contact_cannot_be_switched(): void
    {
        $catalog = app(NoticeTypeCatalog::class);
        $fixed = array_filter($catalog->all(), fn (NoticeTypeEntry $entry): bool => ! $entry->switchable);

        $this->assertSame([WorkflowNoticeType::Contact->value], array_column(array_values($fixed), 'type'));
        $this->assertFalse($catalog->isSwitchable(WorkflowNoticeType::Contact->value));
        $this->assertTrue($catalog->isSwitchable(WorkflowNoticeType::ProcessRequest->value));
        $this->assertFalse($catalog->isSwitchable('test.unlisted'));
        $this->assertNull($catalog->find('test.unlisted'));
    }

    public function test_a_registered_implementation_follows_in_the_order_of_registration(): void
    {
        $this->registerNoticeTypes(['test.b' => true, 'test.a' => false]);

        $all = app(NoticeTypeCatalog::class)->all();

        $expected = [new NoticeTypeEntry('test.b', true), new NoticeTypeEntry('test.a', false)];
        $this->assertEquals($expected, array_slice($all, -2));
    }

    /**
     * @param  list<list<NoticeTypeEntry>>  $answers  起動の実装の後ろに登録する実装の答え(登録の順)
     */
    #[DataProvider('brokenAnswers')]
    public function test_a_broken_answer_is_an_exception_when_bundled(array $answers, string $message): void
    {
        foreach ($answers as $entries) {
            $this->registerNoticeTypeEntries($entries);
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);

        app(NoticeTypeCatalog::class)->all();
    }

    public function test_the_list_is_bundled_once_per_request(): void
    {
        $implementation = $this->registerNoticeTypes(['test.a' => true]);

        app(NoticeTypeCatalog::class)->all();
        app(NoticeTypeCatalog::class)->find('test.a');
        app(NoticeTypeCatalog::class)->isSwitchable('test.a');

        $this->assertSame(1, $implementation->calls);
    }

    public function test_a_type_over_the_length_limit_is_not_well_formed(): void
    {
        $this->assertTrue(NoticeTypeCatalog::isWellFormed('workflow.'.str_repeat('a', 91)));
        $this->assertFalse(NoticeTypeCatalog::isWellFormed('workflow.'.str_repeat('a', 92)));
        $this->assertFalse(NoticeTypeCatalog::isWellFormed("workflow.process_request\n"));
    }
}
