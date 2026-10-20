<?php

namespace Tests\Feature\Migrations;

use App\Enums\Records\RecordFieldType;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordReference;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TransactionalTestCase;

/**
 * 複数選べる参照のマイグレーション(docs の 設計/業務データの入れ物/データモデル/表.md 2.6 の最後、制約の分担と検査.md 7 節):
 * down は足した列と制約を消して、項目ごとに 1 行の一意に戻し、その後の up は今の行(1 つの値の参照)を sort_key = 0・is_multiple = false で
 * そのまま読めるようにする。DDL もテストの TX の中で行い、終わりに巻き戻る。
 */
final class MultipleReferencesMigrationTest extends TransactionalTestCase
{
    private const MIGRATION = '2026_10_19_200001_add_multiple_references_to_records.php';

    public function test_down_drops_the_columns_and_restores_one_reference_per_field(): void
    {
        $this->migrator()->reset([$this->migrationPath()]);

        $this->assertFalse(Schema::hasColumn('record_fields', 'is_multiple'));
        $this->assertFalse(Schema::hasColumn('record_references', 'sort_key'));
        $this->assertFalse(Schema::hasColumn('record_references', 'is_multiple'));
        $this->assertSame(['record_references_record_id_record_field_id_unique'], $this->uniqueNames());
    }

    public function test_up_after_down_keeps_the_current_references_readable(): void
    {
        $this->migrator()->reset([$this->migrationPath()]);
        $field = RecordField::factory()->create(['type' => RecordFieldType::User]);
        $record = Record::factory()->create(['record_module_id' => $field->record_module_id]);
        $user = User::factory()->create();
        DB::table('record_references')->insert([
            'record_module_id' => $field->record_module_id,
            'record_id' => $record->id,
            'record_field_id' => $field->id,
            'user_id' => $user->id,
        ]);

        $this->migrator()->run([$this->migrationPath()]);

        $reference = RecordReference::query()->where('record_id', $record->id)->sole();
        $this->assertSame([0, false], [$reference->sort_key, $reference->is_multiple]);
        $this->assertFalse(RecordField::query()->findOrFail($field->id)->is_multiple);
        $this->assertSame($user->id, $record->load('recordReferences')->referenceValue($field->refresh()));
        $this->assertSame([
            'record_references_record_id_record_field_id_department_id_uniqu',
            'record_references_record_id_record_field_id_sort_key_unique',
            'record_references_record_id_record_field_id_user_id_unique',
        ], $this->uniqueNames());
    }

    /**
     * Laravel のマイグレーターで、このマイグレーションだけを down・up する(パスに渡したファイルのマイグレーションだけが対象になる)。
     */
    private function migrator(): Migrator
    {
        return $this->app->make(Migrator::class);
    }

    private function migrationPath(): string
    {
        return database_path('migrations/'.self::MIGRATION);
    }

    /**
     * 参照の値の行の一意の制約の名前(名前の順)。
     *
     * @return list<string>
     */
    private function uniqueNames(): array
    {
        $rows = DB::select(
            "select conname from pg_constraint where conrelid = 'record_references'::regclass and contype = 'u' "
            .'order by conname',
        );

        return array_column($rows, 'conname');
    }
}
