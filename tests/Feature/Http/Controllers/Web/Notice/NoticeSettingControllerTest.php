<?php

namespace Tests\Feature\Http\Controllers\Web\Notice;

use App\Actions\Notice\NoticeSettingValidator;
use App\Actions\Notice\UpdateNoticeSetting;
use App\Models\Master\User;
use App\Models\Notice\NoticeMute;
use App\Models\Notice\NoticeRequiredType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\RegistersNoticeTypes;
use Tests\Support\TransactionalTestCase;

/**
 * 利用者の画面「通知の設定」の入口 notice.settings.*(docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/利用者と管理の画面.md の
 * 3.2 の行の見え方・3.3 の書き込みの判定・3.4 のゲスト)。初期データの上で、ルートの認可を外さずに開く。
 */
final class NoticeSettingControllerTest extends TransactionalTestCase
{
    use RegistersNoticeTypes;

    private const URL = '/notices/settings';

    /** 初期データの面の並び(種類と作る規則.md の 1 節の見出しの順) */
    private const PREFIXES = ['workflow', 'records', 'meeting'];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
        $this->withoutVite();
        $this->user = $this->seededUser('u0001');
    }

    /**
     * 3.2 の行の見え方の 3 行(必須の行は本人がミュートしていてもミュートしない値)と、面と行の並び(種類と作る規則.md の 1 節。
     * ほかの利用者の行は効かない)。
     */
    public function test_the_page_gives_the_three_looks_of_the_rows_in_the_order_of_the_types(): void
    {
        NoticeRequiredType::factory()->createOne(['type' => 'workflow.process_request']);
        $this->mute($this->user, 'workflow.process_request');
        $this->mute($this->user, 'workflow.transfer');
        $this->mute($this->seededUser('u0002'), 'records.comment');

        $this->actingAs($this->user)->get(self::URL)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Notice/Settings')
            ->where('surfaces', [
                ['prefix' => 'workflow', 'rows' => [
                    self::row('workflow.process_request', false, true),
                    self::row('workflow.transfer', true, false),
                    self::row('workflow.retarget', false, false),
                    self::row('workflow.result', false, false),
                    self::row('workflow.confirm_request', false, false),
                    self::row('workflow.proxy', false, false),
                    self::row('workflow.contact', false, false),
                ]],
                ['prefix' => 'records', 'rows' => [self::row('records.comment', false, false)]],
                ['prefix' => 'meeting', 'rows' => [
                    self::row('meeting.minutes_created', false, false),
                    self::row('meeting.minutes_regenerated', false, false),
                    self::row('meeting.transcription_failed', false, false),
                    self::row('meeting.minutes_failed', false, false),
                ]],
            ])
        );
    }

    /**
     * 0 件の実装の答えは面にならない(種類と作る規則.md の 1 節)。
     */
    public function test_an_empty_answer_makes_no_surface(): void
    {
        $this->registerNoticeTypes([]);

        $this->actingAs($this->user)->get(self::URL)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('surfaces', fn (Collection $surfaces) => $surfaces->pluck('prefix')->all() === self::PREFIXES)
        );
    }

    /**
     * 3.4 のゲストの画面と、3.3 の 1 行目。
     */
    public function test_a_guest_sees_no_surface_and_is_forbidden_to_save(): void
    {
        $this->get(self::URL)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Notice/Settings')
            ->where('surfaces', [])
        );
        $this->save('workflow.transfer', false)->assertForbidden();

        $this->assertSame(0, NoticeMute::query()->count());
    }

    /**
     * 3.3 の 2 行目(キーごとの 422 で書かない)。
     *
     * @param  list<string>  $keys
     */
    #[DataProvider('invalidInputs')]
    public function test_an_invalid_input_is_rejected_by_each_key_without_writing(
        mixed $type,
        mixed $value,
        array $keys,
    ): void {
        $errors = array_fill_keys($keys, NoticeSettingValidator::INVALID_MESSAGE);

        $this->actingAs($this->user)->save($type, $value)->assertRedirect(self::URL)->assertSessionHasErrors($errors);

        $this->assertSame([], $this->mutedTypesOf($this->user));
    }

    /**
     * @return array<string, array{mixed, mixed, list<string>}>
     */
    public static function invalidInputs(): array
    {
        return [
            'unknown type' => ['workflow.no_such_type', false, ['type']],
            'missing type' => [null, false, ['type']],
            'string value' => ['workflow.transfer', 'false', ['muted']],
            'integer value' => ['workflow.transfer', 0, ['muted']],
            'both' => ['workflow.no_such_type', 'false', ['type', 'muted']],
        ];
    }

    /**
     * 3.3 の 3 行目(前にミュートした行も残る)。
     */
    public function test_a_required_type_is_rejected_without_writing(): void
    {
        NoticeRequiredType::factory()->createOne(['type' => 'workflow.result']);
        $this->mute($this->user, 'workflow.result');

        $this->actingAs($this->user)->save('workflow.result', false)
            ->assertRedirect(self::URL)->assertSessionHasErrors(['type' => UpdateNoticeSetting::REQUIRED_MESSAGE]);

        $this->assertSame(['workflow.result'], $this->mutedTypesOf($this->user));
    }

    /**
     * 3.3 の 4・5 行目(同じ値をもう一度送っても同じ)と、元の画面への 303(ルートと文言と検査.md の 5 節)。
     */
    public function test_muting_true_creates_the_row_and_false_deletes_it_idempotently(): void
    {
        $this->actingAs($this->user);

        $this->save('workflow.transfer', true)->assertStatus(303)->assertRedirect(self::URL)
            ->assertSessionHasNoErrors();
        $this->save('workflow.transfer', true)->assertStatus(303)->assertSessionHasNoErrors();
        $this->assertSame(['workflow.transfer'], $this->mutedTypesOf($this->user));

        $this->save('workflow.transfer', false)->assertStatus(303)->assertSessionHasNoErrors();
        $this->save('workflow.transfer', false)->assertStatus(303)->assertSessionHasNoErrors();
        $this->assertSame([], $this->mutedTypesOf($this->user));
    }

    /**
     * 書き込みは本人の行だけに効く(ほかの利用者を指す入力は読まない)。ほかの利用者の同じ種類の行は残り、増えない。
     */
    public function test_saving_touches_only_the_own_row(): void
    {
        $other = $this->seededUser('u0002');
        $this->mute($other, 'records.comment');

        $this->actingAs($this->user)->from(self::URL)
            ->put(self::URL, ['type' => 'meeting.minutes_failed', 'muted' => true, 'user_id' => $other->id])
            ->assertStatus(303);
        $this->save('records.comment', false)->assertStatus(303);

        $this->assertSame(['meeting.minutes_failed'], $this->mutedTypesOf($this->user));
        $this->assertSame(['records.comment'], $this->mutedTypesOf($other));
    }

    /**
     * 値のキー muted が無い保存(別のキーで送った値)は 422 で拒み、書かない(利用者と管理の画面.md の 3.3 の 2 行目)。
     */
    public function test_a_save_with_the_previous_value_key_is_rejected_without_writing(): void
    {
        $this->actingAs($this->user)->from(self::URL)
            ->put(self::URL, ['type' => 'workflow.transfer', 'value' => true])
            ->assertRedirect(self::URL)->assertSessionHasErrors(['muted' => NoticeSettingValidator::INVALID_MESSAGE]);

        $this->assertSame([], $this->mutedTypesOf($this->user));
    }

    /**
     * @return TestResponse<Response>
     */
    private function save(mixed $type, mixed $value): TestResponse
    {
        return $this->from(self::URL)->put(self::URL, ['type' => $type, 'muted' => $value]);
    }

    private function mute(User $user, string $type): void
    {
        NoticeMute::factory()->createOne(['user_id' => $user->id, 'type' => $type]);
    }

    /**
     * @return list<string>
     */
    private function mutedTypesOf(User $user): array
    {
        return array_values(NoticeMute::query()->where('user_id', $user->id)->orderBy('type')->pluck('type')->all());
    }

    private function seededUser(string $code): User
    {
        return User::query()->where('code', $code)->sole();
    }

    /**
     * @return array{type: string, muted: bool, isRequiredType: bool}
     */
    private static function row(string $type, bool $muted, bool $isRequiredType): array
    {
        return ['type' => $type, 'muted' => $muted, 'isRequiredType' => $isRequiredType];
    }
}
