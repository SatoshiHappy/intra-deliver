<?php

namespace Tests\Feature\Actions\Notice;

use App\Actions\Notice\Api\NoticeMessage;
use App\Actions\Notice\Api\SendNotices;
use App\Actions\Notice\UpdateNoticeSetting;
use App\Actions\Notice\UpdateRequiredNoticeType;
use App\Enums\Workflow\WorkflowNoticeType;
use App\Models\Master\User;
use App\Models\Notice\Notice;
use App\Models\Notice\NoticeMute;
use App\Models\Notice\NoticeRequiredType;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 送る口の未読で作るか既読で作るか(docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/種類と作る規則.md の 2 節の表と表の前の確かめ・読む時点、
 * 検査 = ルートと文言と検査.md の 8 節): 表の 4 行、宛先を除かない、一覧に無い種類は宛先が 0 人でも例外、
 * ミュート・必須の付け外しは後に作る通知から効く。確かめるのはテストの前の最後の行より後の行だけ(初期データの件数に依らない)。
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
     * @return array<string, array{bool, bool}> [必須の印, 宛先の外の人のミュートの行]
     */
    public static function outsiderCases(): array
    {
        return [
            '必須・ミュート中' => [true, true],
            '必須でない・ミュートしない' => [false, false],
            '必須でない・ミュート中' => [false, true],
        ];
    }

    /**
     * 1 行目: 送り手の宛先に入らない人には、必須の印・その人の設定によらず作らない(「どれでも」の欄に値を入れて通す)。
     */
    #[DataProvider('outsiderCases')]
    public function test_row_1_a_person_outside_the_recipients_gets_nothing(bool $required, bool $outsiderMuted): void
    {
        [$recipient, $outsider] = $this->userIds(2);

        if ($required) {
            NoticeRequiredType::query()->create(['type' => self::PROCESS->value]);
        }

        if ($outsiderMuted) {
            $this->mute($outsider, self::PROCESS);
        }

        $this->send(self::PROCESS, [$recipient]);

        $this->assertSame([$recipient => false], $this->readByRecipient());
    }

    /**
     * 2 行目: 必須の種類は、ミュートしている人にも、していない人にも未読で作る。
     */
    public function test_row_2_a_required_type_is_unread_for_everyone(): void
    {
        [$muted, $notMuted] = $this->userIds(2);
        $this->mute($muted, self::PROCESS);
        NoticeRequiredType::query()->create(['type' => self::PROCESS->value]);

        $this->send(self::PROCESS, [$muted, $notMuted]);

        $this->assertSame([$muted => false, $notMuted => false], $this->readByRecipient());
    }

    /**
     * 3 行目・4 行目: 必須でない種類は、ミュートしていない人(行が無い)には未読で、ミュートしている人(行がある)には既読で作る
     * (宛先から除かない。既読の日時 = 作った時刻)。ほかの種類をミュートした行は効かない。宛先の重複は 1 行にする。
     */
    public function test_rows_3_and_4_a_muted_person_gets_a_read_row(): void
    {
        [$notMuted, $muted, $mutedAnother] = $this->userIds(3);
        $this->mute($muted, self::PROCESS);
        $this->mute($mutedAnother, WorkflowNoticeType::Result);

        $this->send(self::PROCESS, [$notMuted, $muted, $mutedAnother, $muted]);

        $this->assertSame([$notMuted => false, $muted => true, $mutedAnother => false], $this->readByRecipient());
        $read = $this->newNotices()->where('user_id', $muted)->sole();
        $this->assertEquals($read->created_at, $read->read_at);
    }

    /**
     * 連絡を含む 12 種のどれも同じ規則(連絡もミュートすると既読で作る)。
     */
    public function test_the_contact_follows_the_same_rule(): void
    {
        [$muted] = $this->userIds(1);
        $this->mute($muted, WorkflowNoticeType::Contact);

        $this->send(WorkflowNoticeType::Contact, [$muted]);

        $this->assertSame([$muted => true], $this->readByRecipient());
    }

    /**
     * 宛先の全員がミュートしていても、全員に既読で作る(宛先から除かない)。
     */
    public function test_every_recipient_gets_a_row_even_when_all_muted(): void
    {
        [$first, $second] = $this->userIds(2);
        $this->mute($first, self::PROCESS);
        $this->mute($second, self::PROCESS);

        $this->send(self::PROCESS, [$first, $second]);

        $this->assertSame([$first => true, $second => true], $this->readByRecipient());
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

        $this->assertSame([], $this->readByRecipient());
    }

    /**
     * 読む時点: ミュートした後・必須を付け外しした後に作る通知から効き、前に作った通知の既読・未読は変えない
     * (ミュートを外しても、ミュートの間に既読で作った通知は既読のまま)。必須を外すと前の設定に戻る(必須の付け外しは本人の行を変えない)。
     */
    public function test_a_change_takes_effect_from_the_next_notice_and_keeps_the_earlier_ones(): void
    {
        [$userId] = $this->userIds(1);
        $context = new AccessContext($userId, null, null, CarbonImmutable::today(), AccessContext::LOCALE_CODE);
        $type = self::PROCESS->value;

        $this->send(self::PROCESS, [$userId]);
        app(UpdateNoticeSetting::class)->execute($context, $type, true);
        $this->send(self::PROCESS, [$userId]);
        app(UpdateRequiredNoticeType::class)->execute($type, true);
        $this->send(self::PROCESS, [$userId]);
        app(UpdateRequiredNoticeType::class)->execute($type, false);
        $this->send(self::PROCESS, [$userId]);
        $this->assertTrue(NoticeMute::query()->where(['user_id' => $userId, 'type' => $type])->exists());
        app(UpdateNoticeSetting::class)->execute($context, $type, false);
        $this->send(self::PROCESS, [$userId]);

        // 作った順に: ミュートの前 = 未読 / ミュート中 = 既読 / 必須 = 未読 / 必須を外した後(ミュート中に戻る)= 既読 / ミュートを外した後 = 未読
        $this->assertSame([false, true, false, true, false], $this->readInOrder());
    }

    /**
     * @param  list<int>  $recipients
     */
    private function send(WorkflowNoticeType $type, array $recipients): void
    {
        app(SendNotices::class)->execute(new NoticeMessage($type->value, null, null, null, []), $recipients);
    }

    private function mute(int $userId, WorkflowNoticeType $type): void
    {
        NoticeMute::query()->create(['user_id' => $userId, 'type' => $type->value]);
    }

    /**
     * テストの中で書いた通知の行。
     *
     * @return Builder<Notice>
     */
    private function newNotices(): Builder
    {
        return Notice::query()->where('id', '>', $this->lastNoticeId)->orderBy('id');
    }

    /**
     * テストの中で書いた通知の宛先 → 既読か(書いた順)。
     *
     * @return array<int, bool>
     */
    private function readByRecipient(): array
    {
        $read = [];

        foreach ($this->newNotices()->get() as $notice) {
            $read[$notice->user_id] = $notice->read_at !== null;
        }

        return $read;
    }

    /**
     * テストの中で書いた通知の既読か(書いた順)。
     *
     * @return list<bool>
     */
    private function readInOrder(): array
    {
        $notices = $this->newNotices()->get();

        return array_values($notices->map(fn (Notice $notice): bool => $notice->read_at !== null)->all());
    }

    /**
     * @return list<int>
     */
    private function userIds(int $count): array
    {
        return array_values(array_map('intval', User::query()->orderBy('id')->limit($count)->pluck('id')->all()));
    }
}
