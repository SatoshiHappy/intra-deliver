<?php

namespace Tests\Feature\Models\Records;

use App\Models\Records\RecordFieldHeading;
use App\Models\Records\RecordModule;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 項目の見出し(docs の 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.6 節・同じ階層の README.md の 7 節:
 * 業務モジュールを消すと見出しが消える、名前は空にしない)。ソートキーの範囲は SortKeyColumnsTest、
 * 外部キーの削除の動作とリレーションは ForeignKeysTest。
 */
final class RecordFieldHeadingTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    public function test_rejects_an_empty_name(): void
    {
        $this->assertRejectedBy(
            'record_field_headings_name_check',
            fn () => RecordFieldHeading::factory()->create(['name' => '']),
        );
    }

    public function test_accepts_headings_with_the_same_name_in_a_module(): void
    {
        $heading = RecordFieldHeading::factory()->create(['name' => '基本情報']);

        $this->assertModelExists(RecordFieldHeading::factory()->create([
            'record_module_id' => $heading->record_module_id,
            'name' => '基本情報',
        ]));
    }

    public function test_deleting_the_module_deletes_its_headings(): void
    {
        $heading = RecordFieldHeading::factory()->create();

        DB::table('record_modules')->where('id', $heading->record_module_id)->delete();

        $this->assertModelMissing($heading);
        $this->assertModelExists(RecordModule::factory()->create());
    }
}
