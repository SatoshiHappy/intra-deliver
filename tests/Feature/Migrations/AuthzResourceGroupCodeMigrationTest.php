<?php

namespace Tests\Feature\Migrations;

use App\Models\Authz\AuthzResourceGroup;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TransactionalTestCase;

/**
 * 認可のリソースグループ ID を 255 文字に広げるマイグレーションの down と、その後の up(docs の 設計/共通マスタ/ロールと認可/リソース.md の authz_resource_groups:
 * down は元の型に戻し、元の型に収まらない行が残っていれば失敗させて行を消さない)。DDL もテストの TX の中で行い、終わりに巻き戻る。
 * 広げた後の型そのものは tests/Feature/Models/SchemaTest.php が確かめる。
 */
final class AuthzResourceGroupCodeMigrationTest extends TransactionalTestCase
{
    private const MIGRATION = '2026_10_04_070127_widen_code_in_authz_resource_groups_table.php';

    public function test_down_restores_the_previous_type_and_up_widens_it_again(): void
    {
        $this->migrator()->reset([$this->migrationPath()]);
        $afterDown = $this->codeType();
        $this->migrator()->run([$this->migrationPath()]);

        $this->assertSame(['character varying(100)', 'character varying(255)'], [$afterDown, $this->codeType()]);
    }

    public function test_down_fails_and_keeps_the_row_while_an_id_longer_than_the_previous_type_remains(): void
    {
        $group = AuthzResourceGroup::factory()->create(['code' => str_repeat('a', 101)]);

        try {
            DB::transaction(fn () => $this->migrator()->reset([$this->migrationPath()]));
            $this->fail('元の型に収まらない行が残っていても down が通った');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('value too long for type character varying(100)', $exception->getMessage());
        }

        $this->assertModelExists($group);
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

    private function codeType(): string
    {
        return DB::selectOne(
            "select format_type(atttypid, atttypmod) as type from pg_attribute where attrelid = 'authz_resource_groups'::regclass and attname = 'code'",
        )->type;
    }
}
