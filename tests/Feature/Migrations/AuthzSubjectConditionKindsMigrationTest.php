<?php

namespace Tests\Feature\Migrations;

use App\Models\Authz\AuthzSubjectCondition;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 認可の対象者の条件に IPv4 アドレス・認証状態・期間を足すマイグレーションの down と、その後の up
 * (down は元の列と検査制約に戻し、新しい種類の条件の行が残っていれば
 * 失敗させて行を消さない)。DDL もテストの TX の中で行い、終わりに巻き戻る。
 */
final class AuthzSubjectConditionKindsMigrationTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    private const NEW_COLUMNS = ['ipv4_pattern', 'meta_subject', 'term_start_date', 'term_end_date'];

    private const MIGRATION = '2026_10_04_013641_add_ipv4_pattern_meta_subject_term_to_authz_subject_conditions_table.php';

    private const PREVIOUS_ONE_TARGET = 'authz_subject_conditions_user_id_department_id_company_post_id_role_id_check';

    /**
     * 初期データの条件を消す(このマイグレーションは、migrate:fresh の中で条件の無い表に流れる。初期データには新しい種類(認証状態)の条件があり、down が失敗する)。
     */
    protected function setUp(): void
    {
        parent::setUp();
        AuthzSubjectCondition::query()->delete();
    }

    public function test_down_restores_the_previous_columns_and_one_target_check(): void
    {
        $this->migrator()->reset([$this->migrationPath()]);

        $this->assertSame([], array_values(array_intersect(self::NEW_COLUMNS, Schema::getColumnListing('authz_subject_conditions'))));
        $this->assertSame('CHECK ((num_nonnulls(user_id, department_id, company_post_id, role_id) = 1))', $this->oneTargetCheckDefinition());
    }

    public function test_up_after_down_adds_the_columns_and_checks_again(): void
    {
        $this->migrator()->reset([$this->migrationPath()]);
        $this->migrator()->run([$this->migrationPath()]);

        $this->assertTrue(Schema::hasColumns('authz_subject_conditions', self::NEW_COLUMNS));
        $this->assertSame(
            'CHECK ((num_nonnulls(user_id, department_id, company_post_id, role_id, ipv4_pattern, meta_subject, term_start_date) = 1))',
            $this->oneTargetCheckDefinition(),
        );
    }

    public function test_down_fails_and_keeps_the_row_while_a_condition_of_a_new_kind_remains(): void
    {
        $condition = AuthzSubjectCondition::factory()->forIpv4Pattern()->create();

        $this->assertRejectedBy(self::PREVIOUS_ONE_TARGET, fn () => $this->migrator()->reset([$this->migrationPath()]));

        $this->assertModelExists($condition);
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
     * 「1 つの相手だけ」の検査制約の定義。置き換え前と後で、63 バイトに切り詰めた名前は同じ。
     */
    private function oneTargetCheckDefinition(): string
    {
        return DB::selectOne(
            "select pg_get_constraintdef(oid) as definition from pg_constraint where conrelid = 'authz_subject_conditions'::regclass and conname = ?",
            [substr(self::PREVIOUS_ONE_TARGET, 0, 63)],
        )->definition;
    }
}
