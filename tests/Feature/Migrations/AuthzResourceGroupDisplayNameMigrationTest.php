<?php

namespace Tests\Feature\Migrations;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TransactionalTestCase;

/**
 * 認可のリソースグループの表示名の列を display_name に改名するマイグレーションの down と、その後の up
 * (NOT NULL の制約の名前も共通の規則 9 節の形に揃える)。
 * DDL もテストの TX の中で行い、終わりに巻き戻る。
 */
final class AuthzResourceGroupDisplayNameMigrationTest extends TransactionalTestCase
{
    private const MIGRATION = '2026_10_04_040808_rename_name_to_display_name_in_authz_resource_group_translations_table.php';

    private const TABLE = 'authz_resource_group_translations';

    public function test_up_names_the_column_and_its_not_null_constraint_display_name(): void
    {
        $this->assertTrue(Schema::hasColumn(self::TABLE, 'display_name'));
        $this->assertFalse(Schema::hasColumn(self::TABLE, 'name'));
        $this->assertSame([self::TABLE.'_display_name_not_null'], $this->notNullConstraintNamesOf('display_name'));
    }

    public function test_down_restores_the_previous_column_and_constraint_names(): void
    {
        $this->migrator()->reset([$this->migrationPath()]);

        $this->assertFalse(Schema::hasColumn(self::TABLE, 'display_name'));
        $this->assertSame([self::TABLE.'_name_not_null'], $this->notNullConstraintNamesOf('name'));
    }

    public function test_up_after_down_renames_again(): void
    {
        $this->migrator()->reset([$this->migrationPath()]);
        $this->migrator()->run([$this->migrationPath()]);

        $this->assertSame([self::TABLE.'_display_name_not_null'], $this->notNullConstraintNamesOf('display_name'));
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
     * 列の NOT NULL の制約(PostgreSQL 18 から pg_constraint に contype = 'n' で載る)の名前。
     *
     * @return list<string>
     */
    private function notNullConstraintNamesOf(string $column): array
    {
        return array_column(DB::select(
            "select c.conname from pg_constraint c join pg_attribute a on a.attrelid = c.conrelid and a.attnum = any (c.conkey)
              where c.conrelid = ?::regclass and c.contype = 'n' and a.attname = ?",
            [self::TABLE, $column],
        ), 'conname');
    }
}
