<?php

namespace Tests\Feature\Models\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Master\Department;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRowAccessViewer;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 行の権限の列と表の制約(docs の 設計/業務データの入れ物/データモデル/表.md 2.1・2.2・2.5・2.8、制約の分担と検査.md 7 節): 明細のモジュールは
 * 参照制限の既定を持たず、明細の行はチェックを持たない。関係者の印はユーザー・組織・ユーザーと組織の項目だけ(1 つと複数、明細のモジュールの項目を含む)。
 * 参照者の行は相手を 1 つだけ持ち、同じレコードで並びの位置・同じユーザー・同じ組織を 2 度持たない。レコードを消すと行も消え、行の指すユーザー・組織は消せない。
 * 参照制限の無いレコードに参照者の行を置くことは DB で止めない(業務処理が止める)。既定は制限しない・チェックなし・関係者の印なし。
 */
final class RowAccessConstraintsTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    public function test_a_new_module_and_a_new_record_are_not_restricted_and_a_new_field_grants_nothing(): void
    {
        $field = RecordField::factory()->create(['type' => RecordFieldType::User]);
        $module = RecordModule::query()->findOrFail($field->record_module_id);
        $record = DB::table('records')->insertGetId(['record_module_id' => $module->id, 'field_values' => '{}']);

        $this->assertFalse($module->row_access_restricted_by_default);
        $this->assertFalse(Record::query()->findOrFail($record)->row_access_restricted);
        $this->assertFalse($field->refresh()->grants_row_access);
    }

    public function test_accepts_the_restriction_on_a_module_and_a_record_without_a_parent(): void
    {
        $module = RecordModule::factory()->create(['row_access_restricted_by_default' => true]);
        $record = Record::factory()->create(['record_module_id' => $module->id]);

        $this->assertTrue($module->refresh()->row_access_restricted_by_default);
        $this->assertTrue($record->refresh()->row_access_restricted);
    }

    public function test_rejects_the_default_on_a_details_module(): void
    {
        $this->assertRejectedBy(
            'record_modules_row_access_restricted_by_default_check',
            fn () => RecordModule::factory()->detail()->create(['row_access_restricted_by_default' => true]),
        );
    }

    public function test_rejects_the_restriction_on_a_details_row(): void
    {
        $this->assertRejectedBy(
            'records_parent_record_id_row_access_restricted_check',
            fn () => Record::factory()->detail()->create(['row_access_restricted' => true]),
        );
    }

    public function test_a_viewer_row_has_exactly_one_party(): void
    {
        $record = Record::factory()->create();
        [$user, $department] = [User::factory()->create(), Department::factory()->create()];

        $this->assertRejectedBy(
            'record_row_access_viewers_user_id_department_id_check',
            fn () => $this->viewerRow($record, ['user_id' => $user->id, 'department_id' => $department->id]),
        );
        $this->assertRejectedBy(
            'record_row_access_viewers_user_id_department_id_check',
            fn () => $this->viewerRow($record, []),
        );
    }

    public function test_a_record_does_not_have_the_same_position_user_or_department_twice(): void
    {
        $record = Record::factory()->create();
        [$user, $department] = [User::factory()->create(), Department::factory()->create()];
        $this->viewerRow($record, ['user_id' => $user->id, 'sort_key' => 0]);
        $this->viewerRow($record, ['department_id' => $department->id, 'sort_key' => 1]);

        $this->assertRejectedBy(
            'record_row_access_viewers_record_id_sort_key_unique',
            fn () => $this->viewerRow($record, ['user_id' => User::factory()->create()->id, 'sort_key' => 1]),
        );
        $this->assertRejectedBy(
            'record_row_access_viewers_record_id_user_id_unique',
            fn () => $this->viewerRow($record, ['user_id' => $user->id, 'sort_key' => 2]),
        );
        $this->assertRejectedBy(
            'record_row_access_viewers_record_id_department_id_unique',
            fn () => $this->viewerRow($record, ['department_id' => $department->id, 'sort_key' => 2]),
        );
        $this->viewerRow(Record::factory()->create(), ['user_id' => $user->id]);
    }

    public function test_the_viewer_rows_go_with_the_record_and_keep_their_user_and_department(): void
    {
        $record = Record::factory()->create();
        [$user, $department] = [User::factory()->create(), Department::factory()->create()];
        $this->viewerRow($record, ['user_id' => $user->id, 'sort_key' => 0]);
        $this->viewerRow($record, ['department_id' => $department->id, 'sort_key' => 1]);

        $this->assertRejectedBy('record_row_access_viewers_user_id_foreign', fn () => $user->delete());
        $this->assertRejectedBy('record_row_access_viewers_department_id_foreign', fn () => $department->delete());

        $record->delete();
        $this->assertSame(0, RecordRowAccessViewer::query()->where('record_id', $record->id)->count());
    }

    public function test_the_database_does_not_stop_a_viewer_row_on_a_record_without_the_restriction(): void
    {
        $record = Record::factory()->create();
        $this->viewerRow($record, ['user_id' => User::factory()->create()->id]);

        $this->assertFalse($record->refresh()->row_access_restricted);
        $this->assertSame(1, RecordRowAccessViewer::query()->where('record_id', $record->id)->count());
    }

    /**
     * 参照者の行を業務処理を通さずに作る。
     *
     * @param  array<string, int>  $columns
     */
    private function viewerRow(Record $record, array $columns): void
    {
        RecordRowAccessViewer::query()->create(['record_id' => $record->id, ...$columns]);
    }

    /**
     * @return array<string, array{RecordFieldType, bool}>
     */
    public static function referenceTypes(): array
    {
        return [
            'ユーザー' => [RecordFieldType::User, false],
            'ユーザー(複数)' => [RecordFieldType::User, true],
            '組織' => [RecordFieldType::Department, false],
            '組織(複数)' => [RecordFieldType::Department, true],
            'ユーザーと組織' => [RecordFieldType::UserOrDepartment, false],
            'ユーザーと組織(複数)' => [RecordFieldType::UserOrDepartment, true],
        ];
    }

    #[DataProvider('referenceTypes')]
    public function test_accepts_the_flag_on_a_user_or_department_field(RecordFieldType $type, bool $isMultiple): void
    {
        $field = RecordField::factory()->create([
            'type' => $type,
            'is_multiple' => $isMultiple,
            'grants_row_access' => true,
        ]);

        $this->assertTrue($field->refresh()->grants_row_access);
    }

    public function test_accepts_the_flag_on_a_field_of_a_details_module(): void
    {
        $child = RecordModule::factory()->detail()->create();
        $field = RecordField::factory()->create([
            'record_module_id' => $child->id,
            'type' => RecordFieldType::User,
            'grants_row_access' => true,
        ]);

        $this->assertTrue($field->refresh()->grants_row_access);
    }

    /**
     * @return array<string, array{RecordFieldType}>
     */
    public static function otherTypes(): array
    {
        return [
            '文字列' => [RecordFieldType::String],
            '整数' => [RecordFieldType::Integer],
            '選択' => [RecordFieldType::Choice],
            '真偽' => [RecordFieldType::Boolean],
        ];
    }

    #[DataProvider('otherTypes')]
    public function test_rejects_the_flag_on_a_field_that_is_not_a_user_or_department(RecordFieldType $type): void
    {
        $this->assertRejectedBy(
            'record_fields_type_grants_row_access_check',
            fn () => RecordField::factory()->create(['type' => $type, 'grants_row_access' => true]),
        );
    }

    public function test_rejects_the_flag_on_a_reference_field(): void
    {
        $this->assertRejectedBy(
            'record_fields_type_grants_row_access_check',
            fn () => RecordField::factory()->reference()->create(['grants_row_access' => true]),
        );
    }
}
