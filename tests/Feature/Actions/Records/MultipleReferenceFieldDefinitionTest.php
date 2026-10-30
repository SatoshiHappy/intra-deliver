<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\AddRecordField;
use App\Actions\Records\AddRecordRule;
use App\Actions\Records\UpdateRecordField;
use App\Enums\Records\RecordFieldType;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordReference;
use App\Queries\Records\RecordAdminQuery;
use App\Queries\Records\RecordDefinitionQuery;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * 複数選べる参照の項目の定義(docs の 設計/業務データの入れ物/項目と規則/README.md 1 節、項目と規則/規則と業務処理/失敗と定義の変更.md 11 節の
 * 「項目を変える」、README.md 8.2 の演算子の表、画面と検査.md 13 節の「複数選べる参照」、画面/管理の画面.md 3.3 の影響の件数)。
 */
final class MultipleReferenceFieldDefinitionTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;
    use CatchesValidationErrors;

    private const ONLY_USER_OR_DEPARTMENT = '複数はユーザー・組織・ユーザーと組織の項目だけに設定できます';

    #[TestWith(['user'])]
    #[TestWith(['department'])]
    #[TestWith(['user_or_department'])]
    public function test_adds_a_user_or_department_field_that_holds_multiple_targets(string $type): void
    {
        $this->recordModule('projects');

        $field = $this->add(['code' => 'members', 'name' => 'メンバー', 'type' => $type, 'is_multiple' => true]);
        $omitted = $this->add(['code' => 'owner', 'name' => '担当', 'type' => $type]);

        $this->assertSame([true, false], [$field->refresh()->is_multiple, $omitted->refresh()->is_multiple]);
    }

    /**
     * @param  array<string, string>  $extra
     */
    #[TestWith(['string', []])]
    #[TestWith(['integer', []])]
    #[TestWith(['record', ['referenced_module' => 'customers']])]
    public function test_rejects_multiple_on_another_type_and_writes_nothing(string $type, array $extra): void
    {
        $this->recordModule('projects');
        $this->recordModule('customers');
        $count = RecordField::query()->count();

        $input = ['code' => 'f', 'name' => 'f', 'type' => $type, 'is_multiple' => true, ...$extra];

        $errors = $this->validationErrors(fn () => $this->add($input));

        $this->assertSame(['is_multiple' => self::ONLY_USER_OR_DEPARTMENT], $errors);
        $this->assertSame($count, RecordField::query()->count());
    }

    #[TestWith([RecordFieldType::User])]
    #[TestWith([RecordFieldType::UserOrDepartment])]
    public function test_is_multiple_cannot_be_changed_after_the_field_is_added(RecordFieldType $type): void
    {
        $module = $this->recordModule('projects');
        $this->field($module, 'members', $type, ['is_multiple' => true]);
        $this->field($module, 'owner', $type);
        $this->field($module, 'title');

        $toSingle = $this->validationErrors(fn () => $this->change('members', ['is_multiple' => false]));
        $toMultiple = $this->validationErrors(fn () => $this->change('owner', ['is_multiple' => true, 'name' => '']));
        $onString = $this->validationErrors(fn () => $this->change('title', ['is_multiple' => true]));
        $same = $this->change('members', ['is_multiple' => true, 'name' => 'メンバー']);
        $omitted = $this->change('owner', ['name' => '担当']);

        $this->assertSame(['is_multiple' => '追加後は変更できません'], $toSingle);
        $this->assertSame(['is_multiple' => '追加後は変更できません', 'name' => '名前を入力してください'], $toMultiple);
        $this->assertSame(['is_multiple' => self::ONLY_USER_OR_DEPARTMENT], $onString);
        $this->assertSame([true, 'メンバー'], [$same->is_multiple, $same->name]);
        $this->assertSame([false, '担当'], [$omitted->is_multiple, $omitted->name]);
    }

    public function test_the_definition_carries_is_multiple(): void
    {
        $module = $this->recordModule('projects');
        $this->field($module, 'members', RecordFieldType::User, ['is_multiple' => true]);
        $this->field($module, 'owner', RecordFieldType::User);
        $definitions = app(RecordDefinitionQuery::class);

        $fields = $definitions->definition($definitions->load($module))['fields'];

        $this->assertSame(['members' => true, 'owner' => false], array_column($fields, 'isMultiple', 'code'));
    }

    #[TestWith([RecordFieldType::User, true])]
    #[TestWith([RecordFieldType::UserOrDepartment, true])]
    #[TestWith([RecordFieldType::UserOrDepartment, false])]
    public function test_a_condition_on_a_reference_field_accepts_only_empty_and_not_empty(
        RecordFieldType $type,
        bool $isMultiple,
    ): void {
        $module = $this->recordModule('projects');
        $this->field($module, 'title');
        $this->field($module, 'members', $type, ['is_multiple' => $isMultiple]);
        $rule = fn (array $expression) => app(AddRecordRule::class)->execute('projects', [
            'kind' => 'required_when',
            'target' => 'title',
            'condition' => ['all' => [['field' => 'members', ...$expression]]],
        ]);

        $userId = $this->user()->id;
        $value = $type->hasPartyKind() ? ['type' => 'user', 'id' => $userId] : $userId;
        $errors = $this->validationErrors(fn () => $rule(['op' => 'eq', 'value' => $value]));
        $rule(['op' => 'empty']);
        $rule(['op' => 'not_empty']);

        $this->assertSame(['condition.all.0.op' => 'この項目で使用できる演算子を選択してください'], $errors);
    }

    public function test_the_usage_counts_records_that_hold_a_value_once(): void
    {
        $module = $this->recordModule('projects');
        $members = $this->field($module, 'members', RecordFieldType::User, ['is_multiple' => true]);
        $record = Record::factory()->create(['record_module_id' => $module->id]);

        foreach ([0, 1, 2] as $sortKey) {
            RecordReference::factory()->create([
                'record_module_id' => $module->id,
                'record_id' => $record->id,
                'record_field_id' => $members->id,
                'user_id' => $this->user()->id,
                'sort_key' => $sortKey,
                'is_multiple' => true,
            ]);
        }

        $this->assertSame(1, app(RecordAdminQuery::class)->module('projects')['fieldUsage']['members']);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function add(array $input): RecordField
    {
        return app(AddRecordField::class)->execute('projects', $input);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function change(string $fieldCode, array $input): RecordField
    {
        return app(UpdateRecordField::class)->execute('projects', $fieldCode, $input);
    }
}
