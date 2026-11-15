<?php

namespace Tests\Feature\Actions\Notice;

use App\Actions\Notice\NoticeSettingValidator;
use App\Actions\Notice\UpdateNoticeSetting;
use App\Enums\Workflow\WorkflowNoticeType;
use App\Models\Master\User;
use App\Models\Notice\NoticeOptOut;
use App\Models\Notice\NoticeRequiredType;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * 利用者の通知の設定の書き込み(docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/利用者と管理の画面.md の 3.3 の判定の表、
 * 検査 = ルートと文言と検査.md の 8 節): ゲストの 403・入力の 422(当たるキーごと)・必須の 422 で書かない・行を作る・行を消す
 * (既にある・無いときは何もしない)。書くのは判定を求める者のユーザーの行だけ。
 */
final class UpdateNoticeSettingTest extends TransactionalTestCase
{
    use CatchesValidationErrors;

    private const PROCESS = 'workflow.process_request';

    /**
     * @return array<string, array{mixed, mixed, list<string>}>
     */
    public static function invalidInputs(): array
    {
        return [
            '一覧に無い種類' => ['test.unlisted', false, ['type']],
            '切り替えられない種類(連絡)' => [WorkflowNoticeType::Contact->value, false, ['type']],
            '文字列でない種類' => [['workflow.process_request'], false, ['type']],
            '真偽でない値' => [self::PROCESS, 'false', ['value']],
            '整数の値' => [self::PROCESS, 0, ['value']],
            '種類と値の両方' => ['test.unlisted', null, ['type', 'value']],
        ];
    }

    public function test_row_1_a_guest_is_forbidden_and_nothing_is_written(): void
    {
        $before = NoticeOptOut::query()->count();

        $this->assertThrows(
            fn () => app(UpdateNoticeSetting::class)->execute($this->contextOf(null), self::PROCESS, false),
            AuthorizationException::class,
        );

        $this->assertSame($before, NoticeOptOut::query()->count());
    }

    /**
     * @param  list<string>  $keys
     */
    #[DataProvider('invalidInputs')]
    public function test_row_2_an_invalid_input_is_rejected_per_key_without_writing(
        mixed $type,
        mixed $value,
        array $keys,
    ): void {
        $user = $this->user();
        $contact = WorkflowNoticeType::Contact->value;

        $context = $this->contextOf($user);

        $errors = $this->validationErrors(fn () => app(UpdateNoticeSetting::class)->execute($context, $type, $value));

        $this->assertSame(array_fill_keys($keys, NoticeSettingValidator::INVALID_MESSAGE), $errors);
        $this->assertSame([], $this->optedOutTypes($user));
        $this->assertFalse(NoticeOptOut::query()->where('type', $contact)->exists());
    }

    /**
     * 結果の優先順(表の上の行が先): ゲストは入力が正しくなくても 403。
     */
    public function test_a_guest_with_an_invalid_input_is_forbidden_before_the_input_is_checked(): void
    {
        $before = NoticeOptOut::query()->count();

        $this->assertThrows(
            fn () => app(UpdateNoticeSetting::class)->execute($this->contextOf(null), 'test.unlisted', 'false'),
            AuthorizationException::class,
        );

        $this->assertSame($before, NoticeOptOut::query()->count());
    }

    /**
     * 結果の優先順: 必須の種類に真偽でない値を送ると、必須の 422 でなく値の 422(キー value)。
     */
    public function test_an_invalid_value_for_a_required_type_is_rejected_by_the_value_first(): void
    {
        $user = $this->user();
        NoticeRequiredType::query()->create(['type' => self::PROCESS]);
        $context = $this->contextOf($user);

        $save = fn () => app(UpdateNoticeSetting::class)->execute($context, self::PROCESS, 'false');

        $errors = $this->validationErrors($save);

        $this->assertSame(['value' => NoticeSettingValidator::INVALID_MESSAGE], $errors);
        $this->assertSame([], $this->optedOutTypes($user));
    }

    public function test_row_3_a_required_type_is_rejected_without_writing_either_value(): void
    {
        $user = $this->user();
        NoticeRequiredType::query()->create(['type' => self::PROCESS]);

        foreach ([false, true] as $receives) {
            $errors = $this->validationErrors(
                fn () => app(UpdateNoticeSetting::class)->execute($this->contextOf($user), self::PROCESS, $receives),
            );

            $this->assertSame(['type' => UpdateNoticeSetting::REQUIRED_MESSAGE], $errors);
        }

        $this->assertSame([], $this->optedOutTypes($user));
    }

    public function test_row_4_opting_out_creates_the_row_once(): void
    {
        $user = $this->user();
        $other = $this->user();

        app(UpdateNoticeSetting::class)->execute($this->contextOf($user), self::PROCESS, false);
        $row = NoticeOptOut::query()->where('user_id', $user->id)->sole();
        app(UpdateNoticeSetting::class)->execute($this->contextOf($user), self::PROCESS, false);

        $this->assertSame([self::PROCESS], $this->optedOutTypes($user));
        $this->assertSame($row->id, NoticeOptOut::query()->where('user_id', $user->id)->sole()->id);
        $this->assertSame([], $this->optedOutTypes($other));
    }

    public function test_row_5_receiving_deletes_only_the_own_row_and_does_nothing_without_one(): void
    {
        $user = $this->user();
        $other = $this->user();
        NoticeOptOut::query()->create(['user_id' => $user->id, 'type' => self::PROCESS]);
        NoticeOptOut::query()->create(['user_id' => $user->id, 'type' => 'records.comment']);
        NoticeOptOut::query()->create(['user_id' => $other->id, 'type' => self::PROCESS]);

        app(UpdateNoticeSetting::class)->execute($this->contextOf($user), self::PROCESS, true);
        app(UpdateNoticeSetting::class)->execute($this->contextOf($user), self::PROCESS, true);

        $this->assertSame(['records.comment'], $this->optedOutTypes($user));
        $this->assertSame([self::PROCESS], $this->optedOutTypes($other));
    }

    private function user(): User
    {
        return User::factory()->createOne();
    }

    private function contextOf(?User $user): AccessContext
    {
        return new AccessContext($user?->id, null, null, CarbonImmutable::today(), AccessContext::LOCALE_CODE);
    }

    /**
     * @return list<string> その人が受け取らないにしている種類(値の順)
     */
    private function optedOutTypes(User $user): array
    {
        return array_values(NoticeOptOut::query()->where('user_id', $user->id)->orderBy('type')->pluck('type')->all());
    }
}
