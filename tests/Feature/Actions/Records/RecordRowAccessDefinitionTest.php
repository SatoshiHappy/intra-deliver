<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\AddRecordField;
use App\Actions\Records\UpdateRecordField;
use App\Actions\Records\UpdateRecordModule;
use App\Enums\Records\RecordFieldType;
use App\Models\Master\User;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\BuildsRecordModules;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * 行の権限の定義(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/失敗と定義の変更.md 11 節、レコードの共通機能/業務モジュールの管理.md 2 節、
 * 認可とメニュー/メニューと初期データ.md 8 節の「定義」): 関係者の印はユーザー・組織・ユーザーと組織の項目(1 つと複数、明細のモジュールの項目を含む)だけで、
 * ほかの型は 422 grants_row_access。参照制限の既定は真偽だけで、明細のモジュールは 422 row_access_restricted_by_default。どちらも変えられ、
 * 送らない・null なら今のまま。管理の画面の props に rowAccessRestrictedByDefault(明細のモジュールは null)と grantsRowAccess。
 */
final class RecordRowAccessDefinitionTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use CatchesValidationErrors;

    private const ONLY_USER_OR_DEPARTMENT = '関係者はユーザー・組織・ユーザーと組織の項目だけに設定できます';

    #[TestWith(['user', false])]
    #[TestWith(['user', true])]
    #[TestWith(['department', false])]
    #[TestWith(['department', true])]
    #[TestWith(['user_or_department', false])]
    #[TestWith(['user_or_department', true])]
    public function test_flags_a_user_or_department_field(string $type, bool $isMultiple): void
    {
        $this->recordModule('meetings');

        $common = ['type' => $type, 'is_multiple' => $isMultiple];

        $flagged = $this->addField('meetings', ['code' => 'f', 'name' => 'f', ...$common, 'grants_row_access' => true]);
        $omitted = $this->addField('meetings', ['code' => 'o', 'name' => 'o', ...$common, 'grants_row_access' => null]);

        $this->assertTrue($flagged->refresh()->grants_row_access);
        $this->assertFalse($omitted->refresh()->grants_row_access);
    }

    public function test_flags_a_field_of_a_details_module(): void
    {
        $module = $this->recordModule('meetings');
        $child = $this->childOf($this->detailsField($module, 'rows'));

        $field = $this->addField($child->code, [
            'code' => 'assignee',
            'name' => '担当',
            'type' => 'user',
            'grants_row_access' => true,
        ]);

        $this->assertTrue($field->refresh()->grants_row_access);
    }

    /**
     * @param  array<string, string>  $extra
     */
    #[TestWith(['string', []])]
    #[TestWith(['boolean', []])]
    #[TestWith(['record', ['referenced_module' => 'notes']])]
    public function test_rejects_the_flag_on_another_type_when_adding_and_changing(string $type, array $extra): void
    {
        $module = $this->recordModule('meetings');
        $this->recordModule('notes');
        $count = RecordField::query()->count();

        $input = ['code' => 'f', 'name' => 'f', 'type' => $type, 'grants_row_access' => true, ...$extra];

        $errors = $this->validationErrors(fn () => $this->addField('meetings', $input));

        $this->assertSame(['grants_row_access' => self::ONLY_USER_OR_DEPARTMENT], $errors);
        $this->assertSame($count, RecordField::query()->count());

        $this->field($module, 'title');
        $this->assertSame(
            ['grants_row_access' => self::ONLY_USER_OR_DEPARTMENT],
            $this->validationErrors(fn () => $this->updateField('title', ['grants_row_access' => true])),
        );
    }

    public function test_the_flag_can_be_changed_and_is_kept_when_not_sent(): void
    {
        $module = $this->recordModule('meetings');
        $field = $this->field($module, 'attendee', RecordFieldType::User);
        $update = fn (array $input) => $this->updateField('attendee', $input)->grants_row_access;

        $this->assertTrue($update(['grants_row_access' => true]));
        $this->assertTrue($update(['name' => '出席者']));
        $this->assertTrue($update(['grants_row_access' => null]));
        $this->assertFalse($update(['grants_row_access' => false]));
        $this->assertSame('出席者', $field->refresh()->name);
    }

    public function test_the_default_can_be_changed_and_is_kept_when_not_sent(): void
    {
        $this->recordModule('meetings');
        $update = function (array $input): bool {
            $this->updateModule('meetings', $input);

            return RecordModule::query()->where('code', 'meetings')->sole()->row_access_restricted_by_default;
        };

        $this->assertTrue($update(['row_access_restricted_by_default' => true]));
        $this->assertTrue($update(['name' => '会議']));
        $this->assertTrue($update(['row_access_restricted_by_default' => null]));
        $this->assertFalse($update(['row_access_restricted_by_default' => false]));
    }

    #[TestWith(['true'])]
    #[TestWith([1])]
    #[TestWith([[true]])]
    public function test_rejects_a_default_other_than_a_boolean(mixed $default): void
    {
        $this->recordModule('meetings');

        $this->assertSame(
            ['row_access_restricted_by_default' => '真偽(true・false)で指定してください'],
            $this->validationErrors(
                fn () => $this->updateModule('meetings', ['row_access_restricted_by_default' => $default]),
            ),
        );
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_rejects_a_default_on_a_details_module(bool $default): void
    {
        $child = $this->childOf($this->detailsField($this->recordModule('meetings'), 'rows'));

        $this->assertSame(
            ['row_access_restricted_by_default' => '明細には設定できません'],
            $this->validationErrors(
                fn () => $this->updateModule($child->code, ['row_access_restricted_by_default' => $default]),
            ),
        );
        $this->assertFalse($child->refresh()->row_access_restricted_by_default);
    }

    public function test_the_admin_pages_carry_the_default_and_the_flag(): void
    {
        $this->withoutVite();
        $module = $this->recordModule('meetings');
        $module->update(['row_access_restricted_by_default' => true]);
        $this->field($module, 'attendee', RecordFieldType::User, ['grants_row_access' => true]);
        $child = $this->childOf($this->detailsField($module, 'rows'));
        $this->actingAs(User::query()->where('code', 'u0004')->sole());

        $this->get('/records/admin/modules/meetings')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('module.rowAccessRestrictedByDefault', true)
            ->where('module.fields.0.grantsRowAccess', true)
            ->where('module.fields.1.grantsRowAccess', false));
        $this->assertProp('/records/admin/modules/meetings/edit', 'module.rowAccessRestrictedByDefault', true);
        $this->assertProp("/records/admin/modules/{$child->code}", 'module.rowAccessRestrictedByDefault', null);
        $this->assertProp("/records/admin/modules/{$child->code}/edit", 'module.rowAccessRestrictedByDefault', null);
        $this->assertProp('/records/admin/modules/meetings/fields/attendee/edit', 'field.grantsRowAccess', true);
    }

    public function test_the_admin_entrances_pass_the_default_and_the_flag_to_the_definition_writes(): void
    {
        $module = $this->recordModule('meetings');
        $field = $this->field($module, 'attendee', RecordFieldType::User);
        $this->actingAs(User::query()->where('code', 'u0004')->sole());

        $this->put('/records/admin/modules/meetings', ['row_access_restricted_by_default' => true])
            ->assertStatus(303)
            ->assertSessionHasNoErrors();
        $this->put('/records/admin/modules/meetings/fields/attendee', ['grants_row_access' => true])
            ->assertStatus(303)
            ->assertSessionHasNoErrors();
        $flaggedString = ['code' => 'note', 'name' => 'n', 'type' => 'string', 'grants_row_access' => true];
        $this->post('/records/admin/modules/meetings/fields', $flaggedString)
            ->assertSessionHasErrors(['grants_row_access' => self::ONLY_USER_OR_DEPARTMENT]);

        $this->assertTrue($module->refresh()->row_access_restricted_by_default);
        $this->assertTrue($field->refresh()->grants_row_access);
    }

    private function assertProp(string $url, string $key, mixed $expected): void
    {
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where($key, $expected));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function updateField(string $fieldCode, array $input): RecordField
    {
        return app(UpdateRecordField::class)->execute('meetings', $fieldCode, $input);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function updateModule(string $moduleCode, array $input): void
    {
        app(UpdateRecordModule::class)->execute($moduleCode, $input);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function addField(string $moduleCode, array $input): RecordField
    {
        return app(AddRecordField::class)->execute($moduleCode, $input);
    }
}
