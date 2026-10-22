<?php

namespace Tests\Feature\Migrations;

use App\Models\Master\Department;
use App\Models\Master\User;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 実行に予算の区分と依頼の時点の組織を足すマイグレーション(docs の 設計/AI/README.md 2.1): 前からある行の区分は default、区分は NOT NULL と空でない制約を持つ、
 * 選んだ組織は消せない(RESTRICT)、索引は (budget, created_at) で created_at だけの索引は無い。down は列・制約・索引を戻す。DDL もテストの TX の中で行い、終わりに巻き戻る。
 */
final class AddBudgetAndSelectedDepartmentMigrationTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    private const MIGRATION = '2026_10_19_400002_add_budget_and_selected_department_to_ai_runs_table.php';

    /** このマイグレーションが触らない索引(後のマイグレーションが足した音声の索引を含む。名前の順で、足す・戻す索引の前後に並ぶ) */
    private const KEPT_INDEXES = [
        'ai_runs_input_upload_id_index',
        'ai_runs_pkey',
        'ai_runs_record_id_index',
        'ai_runs_requester_user_id_created_at_index',
    ];

    public function test_the_columns_the_constraints_and_the_indexes_after_up(): void
    {
        $this->assertTrue(Schema::hasColumns('ai_runs', ['budget', 'selected_department_id']));
        $this->assertSame(
            ['ai_runs_budget_created_at_index', ...self::KEPT_INDEXES],
            $this->indexNames(),
        );
        $userId = User::factory()->create()->id;
        $this->assertRejectedAsNull('ai_runs', 'budget', fn () => $this->insertRun($userId, ['budget' => null]));
        $this->assertRejectedBy('ai_runs_budget_check', fn () => $this->insertRun($userId, ['budget' => '']));
    }

    public function test_the_selected_department_cannot_be_deleted_while_a_run_points_at_it(): void
    {
        $department = Department::factory()->create();
        $userId = User::factory()->create()->id;
        $this->insertRun($userId, ['budget' => 'default', 'selected_department_id' => $department->id]);

        $this->assertRejectedBy(
            'ai_runs_selected_department_id_foreign',
            fn () => DB::table('departments')->where('id', $department->id)->delete(),
        );
    }

    public function test_the_runs_from_before_get_the_default_budget_and_no_selected_department(): void
    {
        $this->migrator()->reset([$this->path()]);
        $userId = User::factory()->create()->id;
        $this->insertRun($userId);

        $this->migrator()->run([$this->path()]);

        $row = (array) DB::table('ai_runs')->where('requester_user_id', $userId)
            ->first(['budget', 'selected_department_id']);
        $this->assertSame(['default', null], [$row['budget'], $row['selected_department_id']]);
    }

    public function test_down_drops_the_columns_the_constraint_and_the_index_and_restores_the_created_at_index(): void
    {
        $this->migrator()->reset([$this->path()]);

        $this->assertFalse(Schema::hasColumn('ai_runs', 'budget'));
        $this->assertFalse(Schema::hasColumn('ai_runs', 'selected_department_id'));
        $this->assertSame(
            ['ai_runs_created_at_index', ...self::KEPT_INDEXES],
            $this->indexNames(),
        );
        $this->assertSame(0, DB::scalar("select count(*) from pg_constraint where conname = 'ai_runs_budget_check'"));
    }

    private function migrator(): Migrator
    {
        return $this->app->make(Migrator::class);
    }

    private function path(): string
    {
        return database_path('migrations/'.self::MIGRATION);
    }

    /**
     * @return list<string>
     */
    private function indexNames(): array
    {
        $rows = DB::select("select indexname from pg_indexes where tablename = 'ai_runs' order by indexname");

        return array_column($rows, 'indexname');
    }

    /**
     * 実行の行を表に直に足す(列の足し引きの前後で書けるように、必要な列だけ)。
     *
     * @param  array<string, mixed>  $extra
     */
    private function insertRun(int $userId, array $extra = []): void
    {
        DB::table('ai_runs')->insert([
            'kind' => 'meeting.minutes',
            'status' => 'queued',
            'requester_user_id' => $userId,
            'source_record_ids' => '[]',
            'provider' => 'anthropic',
            'model' => 'claude-haiku-5-5',
            'max_output_tokens' => 100,
            'reserved_cost_usd' => '0.010000',
            'created_at' => now(),
            ...$extra,
        ]);
    }
}
