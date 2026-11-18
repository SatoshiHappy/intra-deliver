<?php

namespace Tests\Feature\Actions\Notice;

use App\Actions\Notice\NoticeSettingValidator;
use App\Actions\Notice\UpdateRequiredNoticeType;
use App\Models\Master\User;
use App\Models\Notice\NoticeMute;
use App\Models\Notice\NoticeRequiredType;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * 必須の印の付け外し(docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/利用者と管理の画面.md の 4.3 の判定の表の 2〜4 行目。
 * 1 行目のルートの認可は入口のテスト): 入力の 422(当たるキーごと)・付ける = 行を作る・外す = 行を消す(既にある・無いときは何もしない)。
 * 付け外しで利用者の設定の行は変わらない。
 */
final class UpdateRequiredNoticeTypeTest extends TransactionalTestCase
{
    use CatchesValidationErrors;

    private const PROCESS = 'workflow.process_request';

    /**
     * @return array<string, array{mixed, mixed, list<string>}>
     */
    public static function invalidInputs(): array
    {
        return [
            '一覧に無い種類' => ['test.unlisted', true, ['type']],
            '文字列でない種類' => [null, true, ['type']],
            '真偽でない値' => [self::PROCESS, 'true', ['required']],
            '種類と値の両方' => [1, 1, ['type', 'required']],
        ];
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
        $errors = $this->validationErrors(fn () => app(UpdateRequiredNoticeType::class)->execute($type, $value));

        $this->assertSame(array_fill_keys($keys, NoticeSettingValidator::INVALID_MESSAGE), $errors);
        $this->assertSame([], $this->requiredTypes());
    }

    /**
     * 必須の印のある種類に真偽でない値を送ると、値の 422(キー required)で、印は残る。
     */
    public function test_an_invalid_value_for_a_required_type_is_rejected_and_the_mark_stays(): void
    {
        NoticeRequiredType::query()->create(['type' => self::PROCESS]);

        $save = fn () => app(UpdateRequiredNoticeType::class)->execute(self::PROCESS, 'false');

        $errors = $this->validationErrors($save);

        $this->assertSame(['required' => NoticeSettingValidator::INVALID_MESSAGE], $errors);
        $this->assertSame([self::PROCESS], $this->requiredTypes());
    }

    public function test_row_3_requiring_creates_the_row_once(): void
    {
        app(UpdateRequiredNoticeType::class)->execute(self::PROCESS, true);
        $row = NoticeRequiredType::query()->sole();
        app(UpdateRequiredNoticeType::class)->execute(self::PROCESS, true);

        $this->assertSame([self::PROCESS], $this->requiredTypes());
        $this->assertSame($row->id, NoticeRequiredType::query()->sole()->id);
    }

    public function test_row_4_unrequiring_deletes_only_the_type_and_does_nothing_without_one(): void
    {
        NoticeRequiredType::query()->create(['type' => self::PROCESS]);
        NoticeRequiredType::query()->create(['type' => 'records.comment']);

        app(UpdateRequiredNoticeType::class)->execute(self::PROCESS, false);
        app(UpdateRequiredNoticeType::class)->execute(self::PROCESS, false);

        $this->assertSame(['records.comment'], $this->requiredTypes());
    }

    public function test_requiring_and_unrequiring_leave_the_settings_of_the_users_as_they_are(): void
    {
        $user = User::factory()->createOne();
        NoticeMute::query()->create(['user_id' => $user->id, 'type' => self::PROCESS]);
        $before = $this->optOutRows();

        app(UpdateRequiredNoticeType::class)->execute(self::PROCESS, true);
        $afterRequiring = $this->optOutRows();
        app(UpdateRequiredNoticeType::class)->execute(self::PROCESS, false);
        $afterUnrequiring = $this->optOutRows();

        $this->assertSame([$before, $before], [$afterRequiring, $afterUnrequiring]);
    }

    /**
     * 受け取りの設定の行の全部(id の順)。
     *
     * @return list<array<string, mixed>>
     */
    private function optOutRows(): array
    {
        $rows = NoticeMute::query()->orderBy('id')->get(['id', 'user_id', 'type', 'updated_at']);

        return array_values($rows->toArray());
    }

    /**
     * @return list<string> 必須の種類(値の順)
     */
    private function requiredTypes(): array
    {
        return array_values(NoticeRequiredType::query()->orderBy('type')->pluck('type')->all());
    }
}
