<?php

namespace Tests\Feature\Actions\Notice;

use App\Actions\Notice\Api\NoticeMessage;
use App\Actions\Notice\Api\SendNotices;
use App\Actions\Notice\UpdateNoticeSetting;
use App\Actions\Notice\UpdateRequiredNoticeType;
use App\Enums\Workflow\WorkflowNoticeType;
use App\Models\Master\User;
use App\Models\Notice\Notice;
use App\Models\Notice\NoticeOptOut;
use App\Models\Notice\NoticeRequiredType;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 送る口の受け取りの絞り込み(docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/種類と作る規則.md の 2 節の表と表の前の確かめ・読む時点、
 * 検査 = ルートと文言と検査.md の 8 節): 表の 5 行、除いた後に 0 人なら何も書かない、一覧に無い種類は宛先が 0 人でも例外、
 * 切り替え・必須の付け外しは後に作る通知から効く。確かめるのはテストの前の最後の行より後の行だけ(初期データの件数に依らない)。
 */
final class SendNoticesReceivingTest extends TransactionalTestCase
{
    private const PROCESS = WorkflowNoticeType::ProcessRequest;

    /** テストの前の最後の通知の行の id */
    private int $lastNoticeId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lastNoticeId = (int) Notice::query()->max('id');
    }

    /**
     * @return array<string, array{WorkflowNoticeType, bool, bool}> [種類, 必須の印, 宛先の外の人の受け取らないの行]
     */
    public static function outsiderCases(): array
    {
        return [
            '切り替えられる・必須・受け取らない' => [self::PROCESS, true, true],
            '切り替えられる・必須でない・受け取る' => [self::PROCESS, false, false],
            '切り替えられない・必須・受け取らない' => [WorkflowNoticeType::Contact, true, true],
        ];
    }

    /**
     * 1 行目: 送り手の宛先に入らない人には、種類・必須の印・その人の設定によらず作らない(「どれでも」の欄に値を入れて通す)。
     */
    #[DataProvider('outsiderCases')]
    public function test_row_1_a_person_outside_the_recipients_gets_nothing(
        WorkflowNoticeType $type,
        bool $required,
        bool $outsiderOptedOut,
    ): void {
        [$recipient, $outsider] = $this->userIds(2);

        if ($required) {
            NoticeRequiredType::query()->create(['type' => $type->value]);
        }

        if ($outsiderOptedOut) {
            $this->optOut($outsider, $type);
        }

        $this->send($type, [$recipient]);

        $this->assertSame([$recipient], $this->recipientsSince());
    }

    /**
     * 2 行目: 切り替えられない種類(連絡)は、受け取らないの行と必須の印があっても作る(設定と必須の印は効かない)。
     */
    public function test_row_2_a_type_that_cannot_be_switched_is_created_whatever_the_rows(): void
    {
        [$withBoth, $optedOutOnly] = $this->userIds(2);
        $contact = WorkflowNoticeType::Contact->value;
        NoticeOptOut::query()->create(['user_id' => $withBoth, 'type' => $contact]);
        NoticeOptOut::query()->create(['user_id' => $optedOutOnly, 'type' => $contact]);
        NoticeRequiredType::query()->create(['type' => $contact]);

        $this->send(WorkflowNoticeType::Contact, [$withBoth, $optedOutOnly]);

        $this->assertSame([$withBoth, $optedOutOnly], $this->recipientsSince());
    }

    /**
     * 3 行目: 必須の種類は、受け取らないにした人にも作る。
     */
    public function test_row_3_a_required_type_is_created_for_a_person_who_opted_out(): void
    {
        [$optedOut] = $this->userIds(1);
        $this->optOut($optedOut, self::PROCESS);
        NoticeRequiredType::query()->create(['type' => self::PROCESS->value]);

        $this->send(self::PROCESS, [$optedOut]);

        $this->assertSame([$optedOut], $this->recipientsSince());
    }

    /**
     * 4 行目・5 行目: 必須でない種類は、受け取らないにした人(行がある)を除き、ほかの人(行が無い)には作る。
     * ほかの種類を受け取らないにした行は効かない。
     */
    public function test_rows_4_and_5_a_person_who_opted_out_of_the_type_is_left_out(): void
    {
        [$receives, $optedOut, $optedOutOfAnother] = $this->userIds(3);
        $this->optOut($optedOut, self::PROCESS);
        $this->optOut($optedOutOfAnother, WorkflowNoticeType::Result);

        $this->send(self::PROCESS, [$receives, $optedOut, $optedOutOfAnother, $optedOut]);

        $this->assertSame([$receives, $optedOutOfAnother], $this->recipientsSince());
    }

    /**
     * 宛先の全員が受け取らないにしていれば、例外にせず 1 行も作らない。
     */
    public function test_nothing_is_written_when_every_recipient_opted_out(): void
    {
        [$first, $second] = $this->userIds(2);
        $this->optOut($first, self::PROCESS);
        $this->optOut($second, self::PROCESS);

        $this->send(self::PROCESS, [$first, $second]);

        $this->assertSame([], $this->recipientsSince());
    }

    /**
     * 表の前の確かめ: 一覧に無い種類は、宛先が 0 人でも例外になり、誰の行も作らない。
     */
    public function test_a_type_outside_the_list_is_rejected_even_without_recipients(): void
    {
        [$recipient] = $this->userIds(1);

        foreach ([[], [$recipient]] as $recipients) {
            $message = new NoticeMessage('test.unlisted', null, null, null, []);
            $send = fn () => app(SendNotices::class)->execute($message, $recipients);

            $this->assertThrows($send, InvalidArgumentException::class, '種類の一覧に無い');
        }

        $this->assertSame([], $this->recipientsSince());
    }

    /**
     * 読む時点: 切り替えた後・必須を付け外しした後に作る通知から効き、前に作った通知は残る(既読・未読とも変えない)。
     * 必須を外すと前の設定に戻る(必須の付け外しは本人の設定の行を変えない)。
     */
    public function test_a_change_takes_effect_from_the_next_notice_and_keeps_the_earlier_ones(): void
    {
        [$userId] = $this->userIds(1);
        $context = new AccessContext($userId, null, null, CarbonImmutable::today(), AccessContext::LOCALE_CODE);
        $type = self::PROCESS->value;
        $this->send(self::PROCESS, [$userId]);
        $earlier = $this->newNotices()->sole();

        app(UpdateNoticeSetting::class)->execute($context, $type, false);
        $this->send(self::PROCESS, [$userId]);
        $this->assertSame([$earlier->id], $this->newNotices()->pluck('id')->all());
        $this->assertNull($earlier->refresh()->read_at);

        app(UpdateRequiredNoticeType::class)->execute($type, true);
        $this->send(self::PROCESS, [$userId]);
        $this->assertSame(2, $this->newNotices()->count());

        app(UpdateRequiredNoticeType::class)->execute($type, false);
        $this->send(self::PROCESS, [$userId]);
        $this->assertSame(2, $this->newNotices()->count());
        $this->assertTrue(NoticeOptOut::query()->where(['user_id' => $userId, 'type' => $type])->exists());

        app(UpdateNoticeSetting::class)->execute($context, $type, true);
        $this->send(self::PROCESS, [$userId]);
        $this->assertSame(3, $this->newNotices()->count());
    }

    /**
     * @param  list<int>  $recipients
     */
    private function send(WorkflowNoticeType $type, array $recipients): void
    {
        app(SendNotices::class)->execute(new NoticeMessage($type->value, null, null, null, []), $recipients);
    }

    private function optOut(int $userId, WorkflowNoticeType $type): void
    {
        NoticeOptOut::query()->create(['user_id' => $userId, 'type' => $type->value]);
    }

    /**
     * テストの中で書いた通知の行。
     *
     * @return Builder<Notice>
     */
    private function newNotices(): Builder
    {
        return Notice::query()->where('id', '>', $this->lastNoticeId);
    }

    /**
     * テストの中で書いた通知の宛先(書いた順)。
     *
     * @return list<int>
     */
    private function recipientsSince(): array
    {
        return array_values(array_map('intval', $this->newNotices()->orderBy('id')->pluck('user_id')->all()));
    }

    /**
     * @return list<int>
     */
    private function userIds(int $count): array
    {
        return array_values(array_map('intval', User::query()->orderBy('id')->limit($count)->pluck('id')->all()));
    }
}
