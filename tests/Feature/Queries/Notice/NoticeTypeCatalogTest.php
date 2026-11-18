<?php

namespace Tests\Feature\Queries\Notice;

use App\Enums\Meeting\MeetingNoticeType;
use App\Enums\Records\RecordNoticeType;
use App\Enums\Workflow\WorkflowNoticeType;
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
     * @return array<string, array{list<list<string>>, string}>
     */
    public static function brokenAnswers(): array
    {
        return [
            '書式の誤り' => [[['Test.a']], '「モジュール.種類」の書式でない'],
            '1 つの答えに同じ値が 2 度' => [[['test.a', 'test.a']], 'test.a が一覧に 2 度ある'],
            '実装の間で同じ値が 2 度' => [[['test.a'], ['test.a']], 'test.a が一覧に 2 度ある'],
            '既にある値をもう 1 つの実装が答える' => [[['records.comment']], 'records.comment が一覧に 2 度ある'],
            '1 つの答えに接頭辞が 2 つ' => [[['test.a', 'other.b']], '接頭辞を 2 つ以上答えている'],
            '2 つの実装が同じ接頭辞' => [[['test.a'], ['test.b']], '接頭辞 test を 2 つの実装が答えている'],
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

        $this->assertSame($expected, app(NoticeTypeCatalog::class)->all());
        $this->assertSame(
            $expected,
            array_map(fn (WorkflowNoticeType|RecordNoticeType|MeetingNoticeType $case): string => $case->value, $enums),
        );
    }

    public function test_has_answers_whether_a_type_is_in_the_list(): void
    {
        $catalog = app(NoticeTypeCatalog::class);

        $this->assertTrue($catalog->has(WorkflowNoticeType::Contact->value));
        $this->assertTrue($catalog->has(WorkflowNoticeType::ProcessRequest->value));
        $this->assertFalse($catalog->has('test.unlisted'));
    }

    public function test_a_registered_implementation_follows_in_the_order_of_registration(): void
    {
        $this->registerNoticeTypes(['test.b', 'test.a']);

        $this->assertSame(['test.b', 'test.a'], array_slice(app(NoticeTypeCatalog::class)->all(), -2));
    }

    /**
     * @param  list<list<string>>  $answers  起動の実装の後ろに登録する実装の答え(登録の順)
     */
    #[DataProvider('brokenAnswers')]
    public function test_a_broken_answer_is_an_exception_when_bundled(array $answers, string $message): void
    {
        foreach ($answers as $answer) {
            $this->registerNoticeTypes($answer);
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);

        app(NoticeTypeCatalog::class)->all();
    }

    public function test_the_list_is_bundled_once_per_request(): void
    {
        $implementation = $this->registerNoticeTypes(['test.a']);

        app(NoticeTypeCatalog::class)->all();
        app(NoticeTypeCatalog::class)->has('test.a');

        $this->assertSame(1, $implementation->calls);
    }

    public function test_a_type_over_the_length_limit_is_not_well_formed(): void
    {
        $this->assertTrue(NoticeTypeCatalog::isWellFormed('workflow.'.str_repeat('a', 91)));
        $this->assertFalse(NoticeTypeCatalog::isWellFormed('workflow.'.str_repeat('a', 92)));
        $this->assertFalse(NoticeTypeCatalog::isWellFormed("workflow.process_request\n"));
    }
}
