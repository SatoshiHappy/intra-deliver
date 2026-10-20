<?php

namespace Tests\Feature\Models\Records;

use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RowAccessScope;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 行の権限の列の制約(docs の 設計/業務データの入れ物/データモデル/表.md 2.1・2.2、制約の分担と検査.md 7 節): 見える範囲は all・related だけで、
 * 明細のモジュールはいつも all。関係する人の旗はユーザー・組織の項目だけ(1 つと複数、明細のモジュールの項目を含む)。既定は all と旗なし。
 */
final class RowAccessConstraintsTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    public function test_a_new_module_has_the_all_row_access_scope_and_a_new_field_grants_nothing(): void
    {
        $field = RecordField::factory()->create(['type' => RecordFieldType::User]);

        $module = RecordModule::query()->findOrFail($field->record_module_id);

        $this->assertSame(RowAccessScope::All, $module->row_access_scope);
        $this->assertFalse($field->refresh()->grants_row_access);
    }

    public function test_accepts_related_on_a_module_without_a_parent(): void
    {
        $module = RecordModule::factory()->create(['row_access_scope' => 'related']);

        $this->assertSame(RowAccessScope::Related, $module->refresh()->row_access_scope);
    }

    public function test_rejects_a_scope_other_than_all_and_related(): void
    {
        $module = RecordModule::factory()->create();

        // 列挙のキャストを通さずに書く(モデルは列挙に無い値を作れない)
        $this->assertRejectedBy(
            'record_modules_row_access_scope_check',
            fn () => DB::table('record_modules')->where('id', $module->id)->update(['row_access_scope' => 'none']),
        );
    }

    public function test_rejects_related_on_a_details_module(): void
    {
        $this->assertRejectedBy(
            'record_modules_parent_record_module_id_row_access_scope_check',
            fn () => RecordModule::factory()->detail()->create(['row_access_scope' => 'related']),
        );
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
