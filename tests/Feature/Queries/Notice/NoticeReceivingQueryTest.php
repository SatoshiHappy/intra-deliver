<?php

namespace Tests\Feature\Queries\Notice;

use App\Models\Master\User;
use App\Models\Notice\NoticeOptOut;
use App\Models\Notice\NoticeRequiredType;
use App\Queries\Notice\NoticeReceivingQuery;
use Tests\Support\TransactionalTestCase;

/**
 * 受け取りの設定と必須の印の読み取り(docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/種類と作る規則.md の 2 節、
 * 利用者と管理の画面.md の 3.2・4.2)。種類 test.* はテストの中だけの値(読み取りは一覧を見ない)。
 */
final class NoticeReceivingQueryTest extends TransactionalTestCase
{
    private NoticeReceivingQuery $receiving;

    private User $user;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->receiving = app(NoticeReceivingQuery::class);
        $this->user = User::factory()->createOne();
        $this->other = User::factory()->createOne();
        NoticeRequiredType::factory()->createOne(['type' => 'test.required']);
        $this->optOut($this->user, 'test.free');
        $this->optOut($this->user, 'test.required');
        $this->optOut($this->other, 'test.others');
    }

    /**
     * 本人の受け取らない種類は、必須の種類の行を除き、ほかの利用者の行を含まない。行の無い利用者は空。
     */
    public function test_the_opted_out_types_of_a_user_leave_out_required_types_and_others_rows(): void
    {
        $types = $this->receiving->optedOutTypesOf($this->user->id);
        sort($types);

        $this->assertSame(['test.free'], $types);
        $this->assertSame(['test.others'], $this->receiving->optedOutTypesOf($this->other->id));
        $this->assertSame([], $this->receiving->optedOutTypesOf(User::factory()->createOne()->id));
    }

    /**
     * 送る口の読み取りも同じ規則: 必須でない種類は行のある人だけ、必須の種類は行があっても誰もいない、宛先が空なら空。
     */
    public function test_the_opted_out_among_the_recipients_follow_the_same_rule(): void
    {
        $recipients = [$this->user->id, $this->other->id];

        $this->assertSame([$this->user->id], $this->receiving->optedOutAmong('test.free', $recipients));
        $this->assertSame([], $this->receiving->optedOutAmong('test.required', $recipients));
        $this->assertSame([$this->other->id], $this->receiving->optedOutAmong('test.others', $recipients));
        $this->assertSame([], $this->receiving->optedOutAmong('test.free', []));
    }

    /**
     * 同じ種類を受け取らない人が複数いても全員を返し、行の無い宛先は返さない。
     */
    public function test_the_opted_out_among_the_recipients_are_every_one_with_a_row(): void
    {
        $this->optOut($this->other, 'test.free');
        $none = User::factory()->createOne();

        $optedOut = $this->receiving->optedOutAmong('test.free', [$this->user->id, $none->id, $this->other->id]);
        sort($optedOut);

        $expected = [$this->user->id, $this->other->id];
        sort($expected);
        $this->assertSame($expected, $optedOut);
    }

    /**
     * 必須の印は、種類のすべてと 1 つの種類の問いが同じ行を読む。
     */
    public function test_the_required_types_and_the_question_for_one_type_read_the_same_marks(): void
    {
        NoticeRequiredType::factory()->createOne(['type' => 'test.another']);
        $types = $this->receiving->requiredTypes();
        sort($types);

        $this->assertSame(['test.another', 'test.required'], $types);
        $this->assertSame(
            [true, false],
            [$this->receiving->isRequiredType('test.required'), $this->receiving->isRequiredType('test.free')],
        );
    }

    private function optOut(User $user, string $type): void
    {
        NoticeOptOut::factory()->createOne(['user_id' => $user->id, 'type' => $type]);
    }
}
