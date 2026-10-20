<?php

namespace Tests\Feature\Migrations;

use App\Models\Authz\AuthzPolicy;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 認可のポリシーにリソースタイプを足して一意を置き換えるマイグレーションの down と、その後の up
 * (down は列を消して元の一意に戻し、
 * リソースタイプだけ違う行が残っていれば失敗させて行を消さない)。DDL もテストの TX の中で行い、終わりに巻き戻る。
 */
final class AuthzPolicyResourceTypeMigrationTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    private const MIGRATION = '2026_10_04_040806_add_resource_type_to_authz_policies_table.php';

    private const PREVIOUS_UNIQUE = 'authz_policies_authz_resource_group_id_authz_subject_group_id_action_unique';

    private const UNIQUE = 'authz_policies_authz_resource_group_id_resource_type_action_authz_subject_group_id_unique';

    /**
     * 初期データのポリシーを消す(このマイグレーションは、migrate:fresh の中でポリシーの無い表に流れる。列を NOT NULL で足すので、行があると up が通らない)。
     */
    protected function setUp(): void
    {
        parent::setUp();
        AuthzPolicy::query()->delete();
    }

    public function test_down_drops_the_column_and_restores_the_previous_unique(): void
    {
        $this->migrator()->reset([$this->migrationPath()]);

        $this->assertFalse(Schema::hasColumn('authz_policies', 'resource_type'));
        $this->assertSame(['UNIQUE (authz_resource_group_id, authz_subject_group_id, action)'], $this->constraintDefinitions(self::PREVIOUS_UNIQUE));
        $this->assertSame([], $this->constraintDefinitions(self::UNIQUE));
    }

    public function test_up_after_down_adds_the_column_and_the_unique_again(): void
    {
        $this->migrator()->reset([$this->migrationPath()]);
        $this->migrator()->run([$this->migrationPath()]);

        $this->assertTrue(Schema::hasColumn('authz_policies', 'resource_type'));
        $this->assertSame(['UNIQUE (authz_resource_group_id, resource_type, action, authz_subject_group_id)'], $this->constraintDefinitions(self::UNIQUE));
        $this->assertSame([], $this->constraintDefinitions(self::PREVIOUS_UNIQUE));
    }

    public function test_down_fails_and_keeps_the_rows_while_policies_differ_only_in_the_resource_type(): void
    {
        $menuGroup = AuthzPolicy::factory()->create(['resource_type' => 'im-menu-group', 'action' => 'execute']);
        $service = AuthzPolicy::factory()->create([...$menuGroup->only(['authz_resource_group_id', 'action', 'authz_subject_group_id']), 'resource_type' => 'service']);

        $this->assertRejectedBy(self::PREVIOUS_UNIQUE, fn () => $this->migrator()->reset([$this->migrationPath()]));

        $this->assertModelExists($menuGroup);
        $this->assertModelExists($service);
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
     * 名前(63 バイトに切り詰めたもの)が一致する authz_policies の制約の定義。無ければ空。
     *
     * @return list<string>
     */
    private function constraintDefinitions(string $name): array
    {
        return array_column(DB::select(
            "select pg_get_constraintdef(oid) as definition from pg_constraint where conrelid = 'authz_policies'::regclass and conname = ?",
            [substr($name, 0, 63)],
        ), 'definition');
    }
}
