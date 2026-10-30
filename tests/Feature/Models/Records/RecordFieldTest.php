<?php

namespace Tests\Feature\Models\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;
use UnexpectedValueException;

/**
 * 項目(docs の 設計/業務データの入れ物/データモデル/ 1 節・2.2・7 節: コードの書式、型は 11 種、型と属性の組(複数はユーザー・組織だけ)、参照先・明細のモジュールの持ち方、
 * 表示の項目は 1 行の文字列で業務モジュールに 1 つまで、既定値の JSON の種類が型に合う)。
 */
final class RecordFieldTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    /**
     * @return array<string, array{string}>
     */
    public static function malformedCodes(): array
    {
        return [
            '大文字' => ['Status'],
            '先頭の数字' => ['2nd_status'],
            '51 文字' => [str_repeat('a', 51)],
        ];
    }

    /**
     * 型と属性の組で拒まれる項目(属性 → 拒む検査制約)。
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidAttributeCombinations(): array
    {
        return [
            '整数の複数行' => [['type' => RecordFieldType::Integer, 'is_multiline' => true], 'record_fields_type_is_multiline_max_length_check'],
            '整数の最大の文字数' => [['type' => RecordFieldType::Integer, 'max_length' => 10], 'record_fields_type_is_multiline_max_length_check'],
            '最大の文字数 0' => [['max_length' => 0], 'record_fields_type_is_multiline_max_length_check'],
            '1 行の最大の文字数 1001' => [['max_length' => 1001], 'record_fields_type_is_multiline_max_length_check'],
            '複数行の最大の文字数 20001' => [['is_multiline' => true, 'max_length' => 20001], 'record_fields_type_is_multiline_max_length_check'],
            '真偽の必須' => [['type' => RecordFieldType::Boolean, 'is_required' => true], 'record_fields_type_is_required_check'],
            '複数行の表示の項目' => [['is_multiline' => true, 'is_title' => true], 'record_fields_type_is_multiline_is_title_check'],
            '整数の表示の項目' => [['type' => RecordFieldType::Integer, 'is_title' => true], 'record_fields_type_is_multiline_is_title_check'],
            '参照先の無い他モジュール参照' => [['type' => RecordFieldType::Record], 'record_fields_type_referenced_record_module_id_check'],
            '明細のモジュールの無い明細' => [['type' => RecordFieldType::Details], 'record_fields_type_child_record_module_id_check'],
            '文字列の複数' => [['is_multiple' => true], 'record_fields_type_is_multiple_check'],
            '整数の複数' => [
                ['type' => RecordFieldType::Integer, 'is_multiple' => true], 'record_fields_type_is_multiple_check',
            ],
        ];
    }

    /**
     * 型と属性の組で受け付ける項目。
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function validAttributeCombinations(): array
    {
        return [
            '1 行の最大の文字数 1000' => [['max_length' => 1000]],
            '複数行の最大の文字数 20000' => [['is_multiline' => true, 'max_length' => 20000]],
            '最大の文字数 1' => [['max_length' => 1]],
            '1 行の文字列の表示の項目' => [['is_title' => true]],
            '真偽の一覧と検索' => [['type' => RecordFieldType::Boolean, 'is_listed' => true, 'is_searchable' => true]],
            'ユーザーの複数' => [['type' => RecordFieldType::User, 'is_multiple' => true]],
            '組織の複数' => [['type' => RecordFieldType::Department, 'is_multiple' => true]],
            'ユーザーと組織' => [['type' => RecordFieldType::UserOrDepartment]],
            'ユーザーと組織の複数' => [['type' => RecordFieldType::UserOrDepartment, 'is_multiple' => true]],
        ];
    }

    /**
     * 型と既定値で拒まれる項目(既定値の JSON の種類が型に合わない、または既定値を持てない型)。
     *
     * @return array<string, array{RecordFieldType, mixed}>
     */
    public static function mismatchedDefaultValues(): array
    {
        return [
            '整数に文字列' => [RecordFieldType::Integer, '12'],
            '真偽に数' => [RecordFieldType::Boolean, 1],
            '文字列に真偽' => [RecordFieldType::String, true],
            '日付に数' => [RecordFieldType::Date, 20261011],
            '日時に数' => [RecordFieldType::Datetime, 1],
            'ユーザー' => [RecordFieldType::User, 'u0001'],
            '組織' => [RecordFieldType::Department, 'd0001'],
        ];
    }

    /**
     * 型に合う既定値。
     *
     * @return array<string, array{RecordFieldType, mixed}>
     */
    public static function matchingDefaultValues(): array
    {
        return [
            '整数' => [RecordFieldType::Integer, 12],
            '小数' => [RecordFieldType::Decimal, '12.5'],
            '真偽' => [RecordFieldType::Boolean, false],
            '文字列' => [RecordFieldType::String, '未着手'],
            '日付' => [RecordFieldType::Date, '2026-10-11'],
            '日時' => [RecordFieldType::Datetime, '2026-10-11T00:30:00Z'],
            '選択' => [RecordFieldType::Choice, 'open'],
        ];
    }

    #[DataProvider('malformedCodes')]
    public function test_rejects_a_malformed_code(string $code): void
    {
        $this->assertRejectedBy('record_fields_code_check', fn () => RecordField::factory()->create(['code' => $code]));
    }

    public function test_accepts_a_code_of_50_characters(): void
    {
        $field = RecordField::factory()->create(['code' => str_repeat('a', 50)]);

        $this->assertSame(str_repeat('a', 50), $field->refresh()->code);
    }

    public function test_rejects_an_empty_name_and_a_type_outside_the_list(): void
    {
        $this->assertRejectedBy('record_fields_name_check', fn () => RecordField::factory()->create(['name' => '']));
        $this->assertRejectedBy('record_fields_type_check', fn () => DB::table('record_fields')->insert([...RecordField::factory()->makeOne()->getAttributes(), 'type' => 'text']));
    }

    public function test_reads_the_type_as_the_enum(): void
    {
        $field = RecordField::factory()->create(['type' => RecordFieldType::Datetime]);

        $this->assertSame(RecordFieldType::Datetime, $field->refresh()->type);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('invalidAttributeCombinations')]
    public function test_rejects_an_invalid_combination_of_type_and_attributes(array $attributes, string $constraint): void
    {
        $this->assertRejectedBy($constraint, fn () => RecordField::factory()->create($attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('validAttributeCombinations')]
    public function test_accepts_a_valid_combination_of_type_and_attributes(array $attributes): void
    {
        $field = RecordField::factory()->create($attributes);

        $this->assertModelExists($field);
    }

    public function test_only_a_reference_field_has_a_referenced_module(): void
    {
        $other = RecordModule::factory()->create();

        $this->assertRejectedBy('record_fields_type_referenced_record_module_id_check', fn () => RecordField::factory()->create(['referenced_record_module_id' => $other->id]));
        $this->assertSame(RecordFieldType::Record, RecordField::factory()->reference()->create()->refresh()->type);
    }

    public function test_only_a_details_field_has_a_child_module(): void
    {
        $child = RecordModule::factory()->detail()->create();

        $this->assertRejectedBy(
            'record_fields_type_child_record_module_id_check',
            fn () => RecordField::factory()->create(['record_module_id' => $child->parent_record_module_id, 'child_record_module_id' => $child->id]),
        );
        $this->assertSame(RecordFieldType::Details, RecordField::factory()->details()->create()->refresh()->type);
    }

    public function test_rejects_a_details_field_whose_child_module_belongs_to_another_module(): void
    {
        $childOfAnother = RecordModule::factory()->detail()->create();
        $moduleWithoutChildren = RecordModule::factory()->create();

        $this->assertRejectedBy(
            'record_fields_child_record_module_id_record_module_id_foreign',
            fn () => RecordField::factory()->details()->create(['child_record_module_id' => $childOfAnother->id]),
        );
        $this->assertRejectedBy(
            'record_fields_child_record_module_id_record_module_id_foreign',
            fn () => RecordField::factory()->details()->create(['child_record_module_id' => $moduleWithoutChildren->id]),
        );
    }

    public function test_rejects_a_second_details_field_for_the_same_child_module(): void
    {
        $field = RecordField::factory()->details()->create();

        $this->assertRejectedBy(
            'record_fields_child_record_module_id_unique',
            fn () => RecordField::factory()->details()->create(['record_module_id' => $field->record_module_id, 'child_record_module_id' => $field->child_record_module_id]),
        );
    }

    public function test_rejects_listing_or_searching_a_details_field(): void
    {
        $this->assertRejectedBy('record_fields_type_is_listed_is_searchable_check', fn () => RecordField::factory()->details()->create(['is_listed' => true]));
        $this->assertRejectedBy('record_fields_type_is_listed_is_searchable_check', fn () => RecordField::factory()->details()->create(['is_searchable' => true]));
    }

    public function test_accepts_one_title_field_per_module(): void
    {
        $title = RecordField::factory()->create(['is_title' => true]);

        $this->assertRejectedBy(
            'record_fields_record_module_id_unique',
            fn () => RecordField::factory()->create(['record_module_id' => $title->record_module_id, 'is_title' => true]),
        );
        $this->assertModelExists(RecordField::factory()->create(['record_module_id' => $title->record_module_id, 'is_title' => false]));
        $this->assertModelExists(RecordField::factory()->create(['is_title' => true]));
    }

    #[DataProvider('mismatchedDefaultValues')]
    public function test_rejects_a_default_value_that_does_not_match_the_type(RecordFieldType $type, mixed $defaultValue): void
    {
        $this->assertRejectedBy('record_fields_default_value_type_check', fn () => RecordField::factory()->create(['type' => $type, 'default_value' => $defaultValue]));
    }

    /**
     * キャストが受けない形の既定値(小数部のある数・配列)も DB が拒む(キャストを通さず、JSON を直接入れる)。整数の項目の既定値は、
     * テキストの形が ^-?[0-9]{1,15}$ の数(5.0 は拒む。PHP が整数として読める形だけ)。
     */
    #[TestWith([RecordFieldType::Integer, '1.5'])]
    #[TestWith([RecordFieldType::Integer, '5.0'])]
    #[TestWith([RecordFieldType::Integer, '1000000000000000'])]
    #[TestWith([RecordFieldType::Decimal, '12.5'])]
    #[TestWith([RecordFieldType::Choice, '["open"]'])]
    public function test_rejects_a_default_value_json_that_does_not_match_the_type(RecordFieldType $type, string $json): void
    {
        $row = [...RecordField::factory()->makeOne(['type' => $type])->getAttributes(), 'default_value' => $json];

        $this->assertRejectedBy('record_fields_default_value_type_check', fn () => DB::table('record_fields')->insert($row));
    }

    public function test_accepts_an_integer_default_value_in_exponent_form_and_reads_it_as_an_integer(): void
    {
        $row = [...RecordField::factory()->makeOne(['type' => RecordFieldType::Integer])->getAttributes(), 'default_value' => '1e3'];
        $id = DB::table('record_fields')->insertGetId($row);

        $this->assertSame(1000, RecordField::query()->findOrFail($id)->default_value);
    }

    public function test_the_default_value_cast_refuses_a_value_other_than_a_string_an_integer_or_a_boolean(): void
    {
        $this->expectException(UnexpectedValueException::class);

        RecordField::factory()->create(['type' => RecordFieldType::Integer, 'default_value' => 1.5]);
    }

    public function test_rejects_a_default_value_on_a_reference_or_details_field(): void
    {
        $this->assertRejectedBy('record_fields_default_value_type_check', fn () => RecordField::factory()->reference()->create(['default_value' => '1']));
        $this->assertRejectedBy('record_fields_default_value_type_check', fn () => RecordField::factory()->details()->create(['default_value' => '1']));
    }

    #[DataProvider('matchingDefaultValues')]
    public function test_accepts_a_default_value_that_matches_the_type_and_reads_it_back(RecordFieldType $type, mixed $defaultValue): void
    {
        $field = RecordField::factory()->create(['type' => $type, 'default_value' => $defaultValue]);

        $this->assertSame($defaultValue, $field->refresh()->default_value);
    }

    public function test_reads_a_missing_default_value_as_null(): void
    {
        $field = RecordField::factory()->create(['default_value' => null]);

        $this->assertNull($field->refresh()->default_value);
    }
}
