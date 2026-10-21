<?php

namespace Tests\Feature\Migrations;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\TransactionalTestCase;

/**
 * 業務モジュール・項目・選択肢に組み込みの旗を足すマイグレーション(docs の 設計/業務ツール/共通データ.md の「入れ物の拡張」の「組み込みの旗」):
 * 組み込みの行が残っている間は down が止まり、無ければ down が 3 表の列を消し、その後の up は前からある行をどれも組み込みでないものにする。DDL もテストの TX の中で行い、終わりに巻き戻る。
 */
final class BuiltinFlagMigrationTest extends TransactionalTestCase
{
    private const MIGRATION = '2026_10_21_200001_add_is_builtin_to_record_definitions.php';

    private const TABLES = ['record_modules', 'record_fields', 'record_field_options'];

    public function test_down_stops_while_builtin_rows_remain_and_keeps_the_flags(): void
    {
        $this->assertTrue(DB::table('record_modules')->where('is_builtin', true)->exists());

        $this->assertThrows(
            fn () => $this->migrator()->reset([$this->migrationPath()]),
            RuntimeException::class,
            '組み込みの定義の行が残っているので戻せない',
        );

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'is_builtin'), $table);
        }
    }

    public function test_without_builtin_rows_down_and_up_pass_and_the_existing_rows_are_not_builtin(): void
    {
        foreach (self::TABLES as $table) {
            DB::table($table)->update(['is_builtin' => false]);
        }

        $this->migrator()->reset([$this->migrationPath()]);
        $this->assertFalse(Schema::hasColumn('record_modules', 'is_builtin'));
        $this->migrator()->run([$this->migrationPath()]);

        foreach (self::TABLES as $table) {
            $this->assertGreaterThan(0, DB::table($table)->count(), $table);
            $this->assertSame([false], DB::table($table)->distinct()->pluck('is_builtin')->all(), $table);
        }
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
}
