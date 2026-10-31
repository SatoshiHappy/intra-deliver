<?php

namespace Tests\Feature\Migrations;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TransactionalTestCase;

/**
 * 行の権限(関係者の印と row_access_scope)を足したマイグレーション(docs の 設計/業務データの入れ物/データモデル/表.md 2.6 の最後、制約の分担と検査.md 7 節): down は足した列と
 * 検査制約を消し、その後の up は今の業務モジュールをどれも all に、今の項目をどれも関係者の印なしにする。後のマイグレーション(参照制限の形への移し =
 * {@see self::LATER})を先に戻してから流す。DDL もテストの TX の中で行い、終わりに巻き戻る。
 */
final class RowAccessMigrationTest extends TransactionalTestCase
{
    private const MIGRATION = '2026_10_19_300001_add_row_access_to_records.php';

    /** row_access_scope を置き換える後のマイグレーション(先に戻す) */
    private const LATER = [
        '2026_10_31_100001_replace_row_access_scope_with_row_access_restricted.php',
        '2026_10_31_100002_create_record_row_access_viewers_table.php',
    ];

    private const CHECKS = [
        'record_fields_type_grants_row_access_check',
        'record_modules_parent_record_module_id_row_access_scope_check',
        'record_modules_row_access_scope_check',
    ];

    public function test_down_drops_the_columns_and_the_checks(): void
    {
        $this->migrator()->reset($this->paths([...self::LATER, self::MIGRATION]));

        $this->assertFalse(Schema::hasColumn('record_modules', 'row_access_scope'));
        $this->assertFalse(Schema::hasColumn('record_fields', 'grants_row_access'));
        $this->assertSame([], $this->checkNames());
    }

    public function test_up_after_down_gives_the_current_modules_the_all_row_access_scope_and_unflags_the_fields(): void
    {
        $this->migrator()->reset($this->paths([...self::LATER, self::MIGRATION]));
        $field = RecordField::factory()->create(['type' => RecordFieldType::User]);
        $child = RecordModule::factory()->create([
            'parent_record_module_id' => $field->record_module_id,
            'icon' => null,
        ]);

        $this->migrator()->run($this->paths([self::MIGRATION]));

        $this->assertSame(
            ['all', 'all'],
            DB::table('record_modules')->whereIn('id', [$field->record_module_id, $child->id])
                ->orderBy('id')
                ->pluck('row_access_scope')
                ->all(),
        );
        $this->assertFalse(DB::table('record_fields')->where('id', $field->id)->value('grants_row_access'));
        $this->assertSame(self::CHECKS, $this->checkNames());
    }

    /**
     * Laravel のマイグレーターで、パスに渡したファイルのマイグレーションだけを down・up する。
     */
    private function migrator(): Migrator
    {
        return $this->app->make(Migrator::class);
    }

    /**
     * @param  list<string>  $files
     * @return list<string>
     */
    private function paths(array $files): array
    {
        return array_map(fn (string $file) => database_path('migrations/'.$file), $files);
    }

    /**
     * このマイグレーションが足す検査制約のうち、今ある名前(名前の順)。
     *
     * @return array<int, mixed>
     */
    private function checkNames(): array
    {
        return DB::table('pg_constraint')
            ->whereIn('conname', self::CHECKS)
            ->orderBy('conname')
            ->pluck('conname')
            ->all();
    }
}
