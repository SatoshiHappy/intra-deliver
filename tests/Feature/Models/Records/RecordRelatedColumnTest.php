<?php

namespace Tests\Feature\Models\Records;

use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRelatedColumn;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 関連レコードの列(docs の 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.7 節・同じ階層の README.md の 7 節)。
 * ソートキーの範囲は SortKeyColumnsTest、外部キーの削除の動作とリレーションは ForeignKeysTest。
 */
final class RecordRelatedColumnTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    public function test_the_factory_makes_rows_of_the_parent_module_for_an_entry_field_of_a_detail_module(): void
    {
        $detail = RecordModule::factory()->detail()->create();
        $entry = RecordField::factory()->reference()->create(['record_module_id' => $detail->id]);

        $column = RecordRelatedColumn::factory()->create(['record_field_id' => $entry->id]);

        $this->assertSame($detail->parent_record_module_id, $column->record_module_id);
    }

    public function test_rejects_a_column_field_of_another_module(): void
    {
        $entry = RecordField::factory()->reference()->create();

        $this->assertRejectedBy(
            'record_related_columns_record_module_id_column_record_field_id_foreign',
            fn () => RecordRelatedColumn::factory()->create([
                'record_field_id' => $entry->id,
                'record_module_id' => $entry->record_module_id,
                'column_record_field_id' => RecordField::factory(),
            ]),
        );
    }

    public function test_rejects_the_same_column_field_twice_on_an_entry(): void
    {
        $column = RecordRelatedColumn::factory()->create();

        $this->assertRejectedBy(
            'record_related_columns_record_field_id_column_record_field_id_unique',
            fn () => RecordRelatedColumn::factory()->create([
                'record_field_id' => $column->record_field_id,
                'record_module_id' => $column->record_module_id,
                'column_record_field_id' => $column->column_record_field_id,
            ]),
        );
    }

    public function test_deleting_the_entry_field_or_the_column_field_deletes_the_row(): void
    {
        $byEntry = RecordRelatedColumn::factory()->create();
        $byColumn = RecordRelatedColumn::factory()->create();

        DB::table('record_fields')->where('id', $byEntry->record_field_id)->delete();
        DB::table('record_fields')->where('id', $byColumn->column_record_field_id)->delete();

        $this->assertModelMissing($byEntry);
        $this->assertModelMissing($byColumn);
    }
}
