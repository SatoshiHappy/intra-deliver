<?php

namespace Tests\Feature\Models\Records;

use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * レコード(docs の 設計/業務データの入れ物/データモデル/表.md 2.5・値の形と直列化.md 3 節・制約の分担と検査.md 7 節: 明細の行の親のレコードと業務モジュールは、行の業務モジュールの定義した親に
 * 合う(複合外部キー)、明細のモジュールの行は親を持つ(生成列 is_detail の複合外部キー)、値は null を持たない JSON のオブジェクト、親の無いレコードの並びは 0、親のレコードを消すと明細の行と参照の値も消える)。
 */
final class RecordTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    /**
     * @return array<string, array{string}>
     */
    public static function invalidFieldValues(): array
    {
        return [
            '配列' => ['[]'],
            '文字列' => ['"a"'],
            '値に null' => ['{"1": "a", "2": null}'],
        ];
    }

    public function test_rejects_a_detail_row_whose_parent_record_belongs_to_another_module(): void
    {
        $detail = Record::factory()->detail()->create();
        $recordOfAnotherModule = Record::factory()->create();

        $this->assertRejectedBy(
            'records_parent_record_module_id_parent_record_id_foreign',
            fn () => Record::factory()->create([...$this->parentColumns($detail), 'parent_record_id' => $recordOfAnotherModule->id]),
        );
    }

    public function test_rejects_a_detail_row_whose_parent_module_is_not_the_defined_parent(): void
    {
        $detail = Record::factory()->detail()->create();
        $recordOfAnotherModule = Record::factory()->create();

        $this->assertRejectedBy(
            'records_record_module_id_parent_record_module_id_foreign',
            fn () => Record::factory()->create([
                ...$this->parentColumns($detail),
                'parent_record_module_id' => $recordOfAnotherModule->record_module_id,
                'parent_record_id' => $recordOfAnotherModule->id,
            ]),
        );
    }

    public function test_rejects_a_parent_on_a_row_of_a_module_without_a_parent(): void
    {
        $parent = Record::factory()->create();
        $moduleWithoutParent = RecordModule::factory()->create();

        $this->assertRejectedBy(
            'records_record_module_id_parent_record_module_id_foreign',
            fn () => Record::factory()->create([
                'record_module_id' => $moduleWithoutParent->id,
                'parent_record_module_id' => $parent->record_module_id,
                'parent_record_id' => $parent->id,
                'sort_key' => 1,
            ]),
        );
    }

    public function test_rejects_a_row_of_a_details_module_without_a_parent_and_derives_is_detail_from_the_parent(): void
    {
        $detail = Record::factory()->detail()->create();
        $top = Record::factory()->create();

        $this->assertSame([true, false], [
            DB::scalar('select is_detail from records where id = ?', [$detail->id]),
            DB::scalar('select is_detail from records where id = ?', [$top->id]),
        ]);
        $this->assertSame([true, false], [
            DB::scalar('select is_detail from record_modules where id = ?', [$detail->record_module_id]),
            DB::scalar('select is_detail from record_modules where id = ?', [$top->record_module_id]),
        ]);
        $this->assertRejectedBy(
            'records_record_module_id_is_detail_foreign',
            fn () => Record::factory()->create(['record_module_id' => $detail->record_module_id]),
        );
    }

    public function test_rejects_only_one_of_the_parent_columns(): void
    {
        $detail = Record::factory()->detail()->create();

        $this->assertRejectedBy(
            'records_parent_record_module_id_parent_record_id_check',
            fn () => Record::factory()->create([...$this->parentColumns($detail), 'parent_record_id' => null, 'sort_key' => 0]),
        );
    }

    #[DataProvider('invalidFieldValues')]
    public function test_rejects_field_values_that_are_not_an_object_without_nulls(string $json): void
    {
        $row = [...Record::factory()->makeOne()->getAttributes(), 'field_values' => $json];

        $this->assertRejectedBy('records_field_values_check', fn () => DB::table('records')->insert($row));
    }

    public function test_writes_field_values_keyed_by_field_id_and_an_empty_object_by_default(): void
    {
        $empty = Record::factory()->create(['field_values' => []]);
        $values = [12 => '社内ポータル刷新', 13 => 1200, 14 => '12.5', 15 => false];
        $filled = Record::factory()->create(['field_values' => $values]);
        $omitted = DB::table('records')->insertGetId(['record_module_id' => $empty->record_module_id]);

        $this->assertSame('object', DB::scalar('select jsonb_typeof(field_values) from records where id = ?', [$empty->id]));
        $this->assertSame([], $empty->refresh()->field_values);
        $this->assertSame($values, $filled->refresh()->field_values);
        $this->assertSame('社内ポータル刷新', DB::scalar('select field_values ->> ? from records where id = ?', ['12', $filled->id]));
        $this->assertSame([], Record::query()->findOrFail($omitted)->field_values);
    }

    public function test_rejects_a_nonzero_sort_key_on_a_record_without_a_parent(): void
    {
        $this->assertRejectedBy('records_parent_record_id_sort_key_check', fn () => Record::factory()->create(['sort_key' => 1]));
    }

    public function test_the_sort_key_of_a_detail_row_is_within_15_digits(): void
    {
        $this->assertSame(999_999_999_999_999, Record::factory()->detail()->create(['sort_key' => 999_999_999_999_999])->refresh()->sort_key);
        $this->assertRejectedBy('records_sort_key_check', fn () => Record::factory()->detail()->create(['sort_key' => 1_000_000_000_000_000]));
    }

    public function test_deleting_a_parent_record_deletes_its_detail_rows_and_their_references(): void
    {
        $detail = Record::factory()->detail()->create();
        $parent = $detail->parentRecord;
        $this->assertInstanceOf(Record::class, $parent);
        $parentReference = RecordReference::factory()->create(['record_module_id' => $parent->record_module_id, 'record_id' => $parent->id]);
        $detailReference = RecordReference::factory()->create(['record_module_id' => $detail->record_module_id, 'record_id' => $detail->id]);

        $parent->delete();

        $this->assertModelMissing($detail);
        $this->assertModelMissing($parentReference);
        $this->assertModelMissing($detailReference);
    }

    /**
     * 明細の行の、業務モジュールと親の列。
     *
     * @return array<string, mixed>
     */
    private function parentColumns(Record $detail): array
    {
        return $detail->only(['record_module_id', 'parent_record_module_id', 'parent_record_id', 'sort_key']);
    }
}
