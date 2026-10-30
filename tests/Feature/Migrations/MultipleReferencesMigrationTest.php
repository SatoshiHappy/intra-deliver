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
use RuntimeException;
use Tests\Support\TransactionalTestCase;

/**
 * 複数選べる参照のマイグレーション(docs の 設計/業務データの入れ物/データモデル/表.md 2.6 の最後、制約の分担と検査.md 7 節):
 * down は足した列と制約を消して、項目ごとに 1 行の一意に戻し、その後の up は今の行(1 つの値の参照)を sort_key = 0・is_multiple = false で
 * そのまま読めるようにする。項目ごとに 2 行以上の参照が残っていると down は理由つきで止まる(消さない)。
 * DDL もテストの TX の中で行い、終わりに巻き戻る。
 */
final class MultipleReferencesMigrationTest extends TransactionalTestCase
{
    private const MIGRATION = '2026_10_19_200001_add_multiple_references_to_records.php';

    public function test_down_drops_the_columns_and_restores_one_reference_per_field(): void
    {
        $this->dropSeededMultipleReferences();
        $this->migrator()->reset([$this->migrationPath()]);

        $this->assertFalse(Schema::hasColumn('record_fields', 'is_multiple'));
        $this->assertFalse(Schema::hasColumn('record_references', 'sort_key'));
        $this->assertFalse(Schema::hasColumn('record_references', 'is_multiple'));
        $this->assertSame(['record_references_record_id_record_field_id_unique'], $this->uniqueNames());
    }

    public function test_down_stops_with_a_reason_and_keeps_the_rows_while_a_field_has_several_references(): void
    {
        $field = RecordField::factory()->create(['type' => RecordFieldType::User, 'is_multiple' => true]);
        $record = Record::factory()->create(['record_module_id' => $field->record_module_id]);

        foreach (User::factory()->count(2)->create() as $sortKey => $user) {
            DB::table('record_references')->insert([
                'record_module_id' => $field->record_module_id,
                'record_id' => $record->id,
                'record_field_id' => $field->id,
                'user_id' => $user->id,
                'sort_key' => $sortKey,
                'is_multiple' => true,
            ]);
        }

        try {
            $this->migrator()->reset([$this->migrationPath()]);
            $this->fail('項目ごとに 2 行以上の参照があるのに、down が止まらなかった');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('2 つ以上', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('record_references', 'sort_key'));
        $this->assertSame(2, DB::table('record_references')->where('record_field_id', $field->id)->count());
    }

    public function test_up_after_down_keeps_the_current_references_readable(): void
    {
        $this->dropSeededMultipleReferences();
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
     * 初期データが入れた、複数選べる参照の値の行を消す(down は項目ごとに 2 行以上の参照があると止まる。見本の会議の関係者はユーザーと組織の
     * 2 行を持つ = 設計/業務ツール/会議/公開デモと画面.md の 8.4)。
     */
    private function dropSeededMultipleReferences(): void
    {
        DB::table('record_references')->where('is_multiple', true)->delete();
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
