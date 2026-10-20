<?php

namespace Tests\Feature\Models\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordField;
use App\Models\Records\RelativeDefault;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;
use UnexpectedValueException;

/**
 * 項目の既定値の相対の既定値と、複数行の最大の文字数の検査制約・キャスト(docs の 設計/業務データの入れ物/データモデル/表.md 2.2、
 * 項目と規則/README.md 1 節の最後・3 節)。DB には業務処理を通さずに書く。
 */
final class RecordFieldRelativeDefaultTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    #[TestWith([RecordFieldType::Date])]
    #[TestWith([RecordFieldType::Datetime])]
    public function test_a_date_or_datetime_accepts_an_object_read_as_the_value_type(RecordFieldType $type): void
    {
        $id = DB::table('record_fields')->insertGetId($this->row($type, '{"relative": "P6M"}'));

        $read = RecordField::query()->findOrFail($id)->default_value;

        $this->assertInstanceOf(RelativeDefault::class, $read);
        $this->assertSame('P6M', $read->duration);
    }

    #[TestWith([RecordFieldType::String])]
    #[TestWith([RecordFieldType::Integer])]
    #[TestWith([RecordFieldType::Choice])]
    public function test_a_field_that_is_not_a_date_or_datetime_rejects_an_object(RecordFieldType $type): void
    {
        $this->assertRejectedBy(
            'record_fields_default_value_type_check',
            fn () => DB::table('record_fields')->insert($this->row($type, '{"relative": "P6M"}')),
        );
    }

    public function test_the_cast_writes_the_value_type_as_the_object(): void
    {
        $duration = RelativeDefault::tryFromDuration('P30D');
        $field = RecordField::factory()->create(['type' => RecordFieldType::Date, 'default_value' => $duration]);
        $stored = DB::table('record_fields')->where('id', $field->id)->value('default_value');

        $this->assertSame(['relative' => 'P30D'], json_decode((string) $stored, true));
    }

    /**
     * キャストは、期間だけを持つオブジェクトでないものを黙って null にせず例外にする(DB はオブジェクトの中身を確かめない)。
     */
    #[TestWith(['{"relative": "P1W"}'])]
    #[TestWith(['{"relative": "P6M", "note": "x"}'])]
    #[TestWith(['{"after": "P6M"}'])]
    #[TestWith(['{}'])]
    public function test_the_cast_refuses_an_object_other_than_a_duration(string $json): void
    {
        $id = DB::table('record_fields')->insertGetId($this->row(RecordFieldType::Date, $json));

        $this->expectException(UnexpectedValueException::class);

        RecordField::query()->findOrFail($id)->getAttribute('default_value');
    }

    public function test_the_cast_refuses_to_write_an_array(): void
    {
        $this->expectException(UnexpectedValueException::class);

        RecordField::factory()->create(['type' => RecordFieldType::Date, 'default_value' => ['relative' => 'P6M']]);
    }

    public function test_a_multiline_field_accepts_a_max_length_up_to_20000(): void
    {
        $this->assertModelExists(RecordField::factory()->create(['is_multiline' => true, 'max_length' => 20000]));
        $this->assertRejectedBy(
            'record_fields_type_is_multiline_max_length_check',
            fn () => RecordField::factory()->create(['is_multiline' => true, 'max_length' => 20001]),
        );
        $this->assertRejectedBy(
            'record_fields_type_is_multiline_max_length_check',
            fn () => RecordField::factory()->create(['max_length' => 1001]),
        );
    }

    /**
     * 読んだ既定値は、jsonb が返す文字と書き戻す文字が違っても、ほかの列だけを変えた保存で変わったとみなされない。
     * NULL の既定値を固定の値にすると変わったとみなされる。
     */
    public function test_saving_another_column_does_not_count_the_read_default_value_as_changed(): void
    {
        $relative = RecordField::factory()->create(['type' => RecordFieldType::Date]);
        DB::table('record_fields')->where('id', $relative->id)->update(['default_value' => '{"relative": "P6M"}']);
        $read = RecordField::query()->findOrFail($relative->id);
        $this->assertInstanceOf(RelativeDefault::class, $read->default_value);

        $read->update(['name' => '見直し日']);
        $this->assertSame(['name'], array_keys(array_diff_key($read->getChanges(), ['updated_at' => true])));

        $emptyId = RecordField::factory()->create(['type' => RecordFieldType::Date])->id;
        $empty = RecordField::query()->findOrFail($emptyId);
        $empty->update(['default_value' => '2026-12-01']);
        $this->assertArrayHasKey('default_value', $empty->getChanges());
        $this->assertSame('2026-12-01', $empty->refresh()->default_value);
    }

    public function test_to_array_gives_the_stored_shape(): void
    {
        $relative = RecordField::factory()->create(['type' => RecordFieldType::Date]);
        DB::table('record_fields')->where('id', $relative->id)->update(['default_value' => '{"relative": "P6M"}']);
        $fixed = RecordField::factory()->create(['type' => RecordFieldType::Date, 'default_value' => '2026-12-01']);

        $arrayOf = fn (RecordField $field) => RecordField::query()->findOrFail($field->id)->toArray();

        $this->assertSame(['relative' => 'P6M'], $arrayOf($relative)['default_value'] ?? null);
        $this->assertSame('2026-12-01', $arrayOf($fixed)['default_value'] ?? null);
    }

    /**
     * 保存形のオブジェクトから作る口は、キーが relative だけで値が長さの形のときだけ作る。
     *
     * @param  array<array-key, mixed>  $value
     */
    #[TestWith([['relative' => 'P6M'], 'P6M'])]
    #[TestWith([['relative' => 'P6M', 'note' => 'x'], null])]
    #[TestWith([['after' => 'P6M'], null])]
    #[TestWith([['relative' => 6], null])]
    #[TestWith([['relative' => 'P06M'], null])]
    public function test_the_value_type_is_made_from_the_stored_object_only(array $value, ?string $duration): void
    {
        $this->assertSame($duration, RelativeDefault::tryFromObject($value)?->duration);
    }

    /**
     * 既定値の JSON を直接持つ項目の行(キャストを通さない)。
     *
     * @return array<string, mixed>
     */
    private function row(RecordFieldType $type, string $json): array
    {
        return [...RecordField::factory()->makeOne(['type' => $type])->getAttributes(), 'default_value' => $json];
    }
}
