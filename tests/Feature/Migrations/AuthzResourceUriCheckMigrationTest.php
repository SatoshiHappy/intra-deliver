<?php

namespace Tests\Feature\Migrations;

use App\Models\Authz\AuthzResource;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 認可のリソースのリソースタイプを広げ、URI の検査制約を足すマイグレーションの down と、その後の up
 * (down は検査制約を消して元の型に戻し、
 * 元の型に収まらない行が残っていれば失敗させて行を消さない)。DDL もテストの TX の中で行い、終わりに巻き戻る。
 */
final class AuthzResourceUriCheckMigrationTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    private const MIGRATION = '2026_10_04_040807_widen_resource_type_and_check_uri_in_authz_resources_table.php';

    private const URI_CHECK = 'authz_resources_uri_resource_type_check';

    public function test_down_drops_the_check_and_restores_the_previous_type(): void
    {
        $this->migrator()->reset([$this->migrationPath()]);

        $this->assertSame('character varying(100)', $this->resourceTypeType());
        $this->assertSame([], $this->uriCheckDefinitions());
    }

    public function test_up_after_down_widens_the_type_and_adds_the_check_again(): void
    {
        $this->migrator()->reset([$this->migrationPath()]);
        $this->migrator()->run([$this->migrationPath()]);

        $this->assertSame('character varying(255)', $this->resourceTypeType());
        $this->assertSame(["CHECK (starts_with((uri)::text, ((resource_type)::text || ':'::text)))"], $this->uriCheckDefinitions());
    }

    public function test_up_fails_while_a_uri_that_does_not_start_with_its_resource_type_remains(): void
    {
        $this->migrator()->reset([$this->migrationPath()]);
        AuthzResource::factory()->create(['resource_type' => 'service', 'uri' => 'im-menu-group:a']);

        $this->assertRejectedBy(self::URI_CHECK, fn () => $this->migrator()->run([$this->migrationPath()]));
    }

    public function test_down_fails_and_keeps_the_row_while_a_resource_type_longer_than_the_previous_type_remains(): void
    {
        $resourceType = str_repeat('a', 101);
        $resource = AuthzResource::factory()->create(['resource_type' => $resourceType, 'uri' => "{$resourceType}:a"]);

        try {
            DB::transaction(fn () => $this->migrator()->reset([$this->migrationPath()]));
            $this->fail('元の型に収まらない行が残っていても down が通った');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('value too long for type character varying(100)', $exception->getMessage());
        }

        $this->assertModelExists($resource);
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

    private function resourceTypeType(): string
    {
        return DB::selectOne(
            "select format_type(atttypid, atttypmod) as type from pg_attribute where attrelid = 'authz_resources'::regclass and attname = 'resource_type'",
        )->type;
    }

    /**
     * URI の検査制約の定義。無ければ空。
     *
     * @return list<string>
     */
    private function uriCheckDefinitions(): array
    {
        return array_column(DB::select(
            "select pg_get_constraintdef(oid) as definition from pg_constraint where conrelid = 'authz_resources'::regclass and conname = ?",
            [self::URI_CHECK],
        ), 'definition');
    }
}
