<?php

namespace Tests\Feature\Http\Controllers\Web\Notice;

use App\Actions\Authz\CreatePolicy;
use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Actions\Authz\SubjectConditionInput;
use App\Actions\Notice\NoticeSettingValidator;
use App\Enums\Authz\MetaSubject;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Authz\ServiceAction;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Master\User;
use App\Models\Notice\NoticeOptOut;
use App\Models\Notice\NoticeRequiredType;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TransactionalTestCase;

/**
 * 管理の画面「通知の管理」の入口 notice.admin.*(docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/利用者と管理の画面.md の
 * 4.1 の使える人・4.2 のページ・4.3 の書き込みの判定)。初期データの上で、ルートの認可を外さずに開く。
 */
final class NoticeAdminControllerTest extends TransactionalTestCase
{
    private const URL = '/notices/admin';

    private const BUNDLE = 'http-services.notice.admin';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
        $this->withoutVite();
    }

    /**
     * 4.1 の使える人と 4.3 の 1 行目を、判定を求める者 × 束の実行の許可に展開して通す(画面と書き込みの両方)。
     */
    #[DataProvider('requesters')]
    public function test_only_the_requesters_permitted_the_bundle_open_the_page_and_save(
        ?string $userCode,
        bool $isGranted,
        bool $passes,
    ): void {
        if ($isGranted) {
            $this->grantBundle($userCode);
        }

        if ($userCode !== null) {
            $this->actingAs($this->seededUser($userCode));
        }

        $this->get(self::URL)->assertStatus($passes ? 200 : 403);
        $this->save('workflow.result', true)->assertStatus($passes ? 303 : 403);

        $this->assertSame($passes ? ['workflow.result'] : [], $this->requiredTypes());
    }

    /**
     * @return array<string, array{string|null, bool, bool}>
     */
    public static function requesters(): array
    {
        return [
            'guest' => [null, false, false],
            'general user' => ['u0001', false, false],
            'system administrator' => ['u0004', false, true],
            'general user permitted the bundle' => ['u0001', true, true],
            'guest permitted the bundle' => [null, true, true],
        ];
    }

    /**
     * 4.2 の行の 2 通りと、面と行の並び(種類と作る規則.md の 1 節)。
     */
    public function test_the_page_gives_required_and_not_required_rows_in_the_order_of_the_types(): void
    {
        NoticeRequiredType::factory()->createOne(['type' => 'workflow.transfer']);
        NoticeRequiredType::factory()->createOne(['type' => 'meeting.minutes_failed']);

        $this->actingAs($this->seededUser('u0004'));

        $this->get(self::URL)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Notice/Admin')
            ->where('surfaces', [
                ['prefix' => 'workflow', 'rows' => [
                    self::row('workflow.process_request', false),
                    self::row('workflow.transfer', true),
                    self::row('workflow.retarget', false),
                    self::row('workflow.result', false),
                    self::row('workflow.confirm_request', false),
                    self::row('workflow.proxy', false),
                ]],
                ['prefix' => 'records', 'rows' => [self::row('records.comment', false)]],
                ['prefix' => 'meeting', 'rows' => [
                    self::row('meeting.minutes_created', false),
                    self::row('meeting.minutes_regenerated', false),
                    self::row('meeting.transcription_failed', false),
                    self::row('meeting.minutes_failed', true),
                ]],
            ])
        );
    }

    /**
     * 4.3 の 2 行目(キーごとの 422 で書かない)。
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

        $this->actingAs($this->seededUser('u0004'))->save($type, $value)
            ->assertRedirect(self::URL)->assertSessionHasErrors($errors);

        $this->assertSame([], $this->requiredTypes());
    }

    /**
     * @return array<string, array{mixed, mixed, list<string>}>
     */
    public static function invalidInputs(): array
    {
        return [
            'contact' => ['workflow.contact', true, ['type']],
            'unknown type' => ['records.no_such_type', true, ['type']],
            'string value' => ['records.comment', 'true', ['value']],
            'both' => ['workflow.contact', 1, ['type', 'value']],
        ];
    }

    /**
     * 4.3 の 3・4 行目(同じ値をもう一度送っても同じ。利用者の設定は書き換えない)と、元の画面への 303(ルートと文言と検査.md の 5 節)。
     */
    public function test_required_adds_the_mark_and_not_required_removes_it_without_touching_user_settings(): void
    {
        $user = $this->seededUser('u0001');
        NoticeOptOut::factory()->createOne(['user_id' => $user->id, 'type' => 'records.comment']);
        $this->actingAs($this->seededUser('u0004'));

        $this->save('records.comment', true)->assertStatus(303)->assertRedirect(self::URL)->assertSessionHasNoErrors();
        $this->save('records.comment', true)->assertStatus(303)->assertSessionHasNoErrors();
        $this->assertSame(['records.comment'], $this->requiredTypes());

        $this->save('records.comment', false)->assertStatus(303)->assertSessionHasNoErrors();
        $this->save('records.comment', false)->assertStatus(303)->assertSessionHasNoErrors();
        $this->assertSame([], $this->requiredTypes());
        $this->assertSame(
            [[$user->id, 'records.comment']],
            NoticeOptOut::query()->get()->map(fn (NoticeOptOut $row) => [$row->user_id, $row->type])->all(),
        );
    }

    /**
     * 束「通知の管理」の実行を、利用者 1 人(null = ゲストユーザ)に許す(認可設定の画面のセルと同じ置き場 = 束の接頭辞のリソースグループ)。
     */
    private function grantBundle(?string $userCode): void
    {
        $condition = $userCode === null
            ? new SubjectConditionInput(metaSubject: MetaSubject::Anonymous)
            : new SubjectConditionInput(userId: $this->seededUser($userCode)->id);
        $subject = app(FindOrCreateSingleConditionSubjectGroup::class)->execute($condition, [])->id;
        $bundle = AuthzResourceGroup::query()->where('code', self::BUNDLE)->sole()->id;

        app(CreatePolicy::class)->execute(
            $bundle,
            $subject,
            ServiceAction::RESOURCE_TYPE,
            ServiceAction::Execute->value,
            PolicyEffect::Permit,
        );
    }

    /**
     * @return TestResponse<Response>
     */
    private function save(mixed $type, mixed $value): TestResponse
    {
        return $this->from(self::URL)->put(self::URL, ['type' => $type, 'value' => $value]);
    }

    /**
     * @return list<string>
     */
    private function requiredTypes(): array
    {
        return array_values(NoticeRequiredType::query()->orderBy('type')->pluck('type')->all());
    }

    private function seededUser(string $code): User
    {
        return User::query()->where('code', $code)->sole();
    }

    /**
     * @return array{type: string, isRequiredType: bool}
     */
    private static function row(string $type, bool $isRequiredType): array
    {
        return ['type' => $type, 'isRequiredType' => $isRequiredType];
    }
}
