<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\AddRecordField;
use App\Actions\Records\UpdateRecordField;
use App\Actions\Records\UpdateRecordModule;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RowAccessScope;
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
 * 認可とメニュー/メニューと初期データ.md 8 節の「定義」): 関係する人の旗はユーザー・組織の項目(1 つと複数、明細のモジュールの項目を含む)だけで、
 * ほかの型は 422 grants_row_access。見える範囲は all・related だけで、明細のモジュールは 422 row_access_scope。どちらも変えられ、送らない・null なら今のまま。
 * 管理の画面の props に rowAccessScope(明細のモジュールは null)と grantsRowAccess。
 */
final class RecordRowAccessDefinitionTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use CatchesValidationErrors;

    private const ONLY_USER_OR_DEPARTMENT = '関係する人はユーザー・組織の項目だけに設定できます';

    #[TestWith(['user', false])]
    #[TestWith(['user', true])]
    #[TestWith(['department', false])]
    #[TestWith(['department', true])]
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

    public function test_the_scope_can_be_changed_and_is_kept_when_not_sent(): void
    {
        $this->recordModule('meetings');
        $update = function (array $input): RowAccessScope {
            $this->updateModule('meetings', $input);

            return RecordModule::query()->where('code', 'meetings')->sole()->row_access_scope;
        };

        $this->assertSame(RowAccessScope::Related, $update(['row_access_scope' => 'related']));
        $this->assertSame(RowAccessScope::Related, $update(['name' => '会議']));
        $this->assertSame(RowAccessScope::Related, $update(['row_access_scope' => null]));
        $this->assertSame(RowAccessScope::All, $update(['row_access_scope' => 'all']));
    }

    #[TestWith(['none'])]
    #[TestWith([1])]
    #[TestWith([['related']])]
    public function test_rejects_a_scope_other_than_all_and_related(mixed $scope): void
    {
        $this->recordModule('meetings');

        $this->assertSame(
            ['row_access_scope' => '見える範囲は全員か関係する人だけを選択してください'],
            $this->validationErrors(fn () => $this->updateModule('meetings', ['row_access_scope' => $scope])),
        );
    }

    #[TestWith(['all'])]
    #[TestWith(['related'])]
    public function test_rejects_a_scope_on_a_details_module(string $scope): void
    {
        $child = $this->childOf($this->detailsField($this->recordModule('meetings'), 'rows'));

        $this->assertSame(
            ['row_access_scope' => '明細には設定できません'],
            $this->validationErrors(fn () => $this->updateModule($child->code, ['row_access_scope' => $scope])),
        );
        $this->assertSame(RowAccessScope::All, $child->refresh()->row_access_scope);
    }

    public function test_the_admin_pages_carry_the_scope_and_the_flag(): void
    {
        $this->withoutVite();
        $module = $this->recordModule('meetings');
        $module->update(['row_access_scope' => RowAccessScope::Related]);
        $this->field($module, 'attendee', RecordFieldType::User, ['grants_row_access' => true]);
        $child = $this->childOf($this->detailsField($module, 'rows'));
        $this->actingAs(User::query()->where('code', 'u0004')->sole());

        $this->get('/records/admin/modules/meetings')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('module.rowAccessScope', 'related')
            ->where('module.fields.0.grantsRowAccess', true)
            ->where('module.fields.1.grantsRowAccess', false));
        $this->assertProp('/records/admin/modules/meetings/edit', 'module.rowAccessScope', 'related');
        $this->assertProp("/records/admin/modules/{$child->code}", 'module.rowAccessScope', null);
        $this->assertProp("/records/admin/modules/{$child->code}/edit", 'module.rowAccessScope', null);
        $this->assertProp('/records/admin/modules/meetings/fields/attendee/edit', 'field.grantsRowAccess', true);
    }

    public function test_the_admin_entrances_pass_the_scope_and_the_flag_to_the_definition_writes(): void
    {
        $module = $this->recordModule('meetings');
        $field = $this->field($module, 'attendee', RecordFieldType::User);
        $this->actingAs(User::query()->where('code', 'u0004')->sole());

        $this->put('/records/admin/modules/meetings', ['row_access_scope' => 'related'])
            ->assertStatus(303)
            ->assertSessionHasNoErrors();
        $this->put('/records/admin/modules/meetings/fields/attendee', ['grants_row_access' => true])
            ->assertStatus(303)
            ->assertSessionHasNoErrors();
        $flaggedString = ['code' => 'note', 'name' => 'n', 'type' => 'string', 'grants_row_access' => true];
        $this->post('/records/admin/modules/meetings/fields', $flaggedString)
            ->assertSessionHasErrors(['grants_row_access' => self::ONLY_USER_OR_DEPARTMENT]);

        $this->assertSame(RowAccessScope::Related, $module->refresh()->row_access_scope);
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
