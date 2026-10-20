<?php

namespace Tests\Feature\Migrations;

use App\Models\Authz\AuthzSubjectCondition;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 認可の対象者の条件に子の対象者と除外を足すマイグレーションの down と、その後の up
 * (down は元の列と検査制約に戻し、子の対象者だけを指す条件の行が
 * 残っていれば失敗させて行を消さない。除外の条件の行が残っていても失敗させる = 列を消すと条件が逆の意味になるため)。
 * DDL もテストの TX の中で行い、終わりに巻き戻る。
 */
final class AuthzSubjectConditionChildAndNegationMigrationTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    private const NEW_COLUMNS = ['child_authz_subject_group_id', 'is_negated'];

    private const MIGRATION = '2026_10_04_042507_add_child_authz_subject_group_id_is_negated_to_authz_subject_conditions_table.php';

    /** 置き換え前と後で、63 バイトに切り詰めた名前は同じ */
    private const ONE_TARGET = 'authz_subject_conditions_user_id_department_id_company_post_id_role_id_ipv4_pattern_meta_subject_term_start_date_check';

    private const CHILD_IS_NOT_ITSELF = 'authz_subject_conditions_child_authz_subject_group_id_authz_subject_group_id_check';

    public function test_down_restores_the_previous_columns_and_one_target_check(): void
    {
        $this->migrator()->reset([$this->migrationPath()]);

        $this->assertSame([], array_values(array_intersect(self::NEW_COLUMNS, Schema::getColumnListing('authz_subject_conditions'))));
        $this->assertSame(
            ['CHECK ((num_nonnulls(user_id, department_id, company_post_id, role_id, ipv4_pattern, meta_subject, term_start_date) = 1))'],
            $this->checkDefinitions(self::ONE_TARGET),
        );
        $this->assertSame([], $this->checkDefinitions(self::CHILD_IS_NOT_ITSELF));
    }

    public function test_up_after_down_adds_the_columns_and_checks_again(): void
    {
        $this->migrator()->reset([$this->migrationPath()]);
        $this->migrator()->run([$this->migrationPath()]);

        $this->assertTrue(Schema::hasColumns('authz_subject_conditions', self::NEW_COLUMNS));
        $this->assertSame(
            ['CHECK ((num_nonnulls(user_id, department_id, company_post_id, role_id, ipv4_pattern, meta_subject, term_start_date, child_authz_subject_group_id) = 1))'],
            $this->checkDefinitions(self::ONE_TARGET),
        );
        $this->assertSame(['CHECK ((child_authz_subject_group_id <> authz_subject_group_id))'], $this->checkDefinitions(self::CHILD_IS_NOT_ITSELF));
    }

    public function test_down_fails_and_keeps_the_row_while_a_condition_on_a_child_subject_group_remains(): void
    {
        $condition = AuthzSubjectCondition::factory()->forChildSubjectGroup()->create();

        $this->assertRejectedBy(self::ONE_TARGET, fn () => $this->migrator()->reset([$this->migrationPath()]));

        $this->assertModelExists($condition);
    }

    public function test_down_fails_and_keeps_the_row_while_a_negated_condition_remains(): void
    {
        $condition = AuthzSubjectCondition::factory()->negated()->create();

        try {
            DB::transaction(fn () => $this->migrator()->reset([$this->migrationPath()]));
            $this->fail('除外の条件の行が残っていても down が通った');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('除外の条件の行が残っている', $exception->getMessage());
        }

        $this->assertTrue($condition->refresh()->is_negated);
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
     * 名前(63 バイトに切り詰めたもの)が一致する検査制約の定義。無ければ空。
     *
     * @return list<string>
     */
    private function checkDefinitions(string $name): array
    {
        return array_column(DB::select(
            "select pg_get_constraintdef(oid) as definition from pg_constraint where conrelid = 'authz_subject_conditions'::regclass and conname = ?",
            [substr($name, 0, 63)],
        ), 'definition');
    }
}
