<?php

namespace Tests\Feature\Migrations;

use App\Models\Workflow\WorkflowCase;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 案件の record_id の部分一意索引を一時保存でない行に広げるマイグレーションの down と、その後の up(重なる行が残っていれば up は失敗し、
 * 行を消さない)。DDL もテストの TX の中で行い、終わりに巻き戻る。
 */
final class WorkflowCaseRecordUniqueMigrationTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    private const MIGRATION = '2026_11_17_100001_widen_workflow_cases_record_id_unique.php';

    private const RECORD_UNIQUE = 'workflow_cases_record_id_unique';

    public function test_down_restores_the_index_of_active_cases_only(): void
    {
        $this->migrator()->reset([$this->migrationPath()]);

        $this->assertStringEndsWith("WHERE ((state)::text = 'active'::text)", $this->indexDefinition());
    }

    public function test_up_after_down_widens_the_index_to_non_draft_cases(): void
    {
        $this->migrator()->reset([$this->migrationPath()]);
        $this->migrator()->run([$this->migrationPath()]);

        $this->assertStringEndsWith("WHERE ((state)::text <> 'draft'::text)", $this->indexDefinition());
    }

    public function test_up_fails_and_keeps_the_rows_while_a_completed_and_an_active_case_share_a_record(): void
    {
        $this->migrator()->reset([$this->migrationPath()]);
        $completed = WorkflowCase::factory()->completed()->create();
        $active = WorkflowCase::factory()->active()->create(['record_id' => $completed->record_id]);

        $this->assertRejectedBy(self::RECORD_UNIQUE, fn () => $this->migrator()->run([$this->migrationPath()]));

        $this->assertModelExists($completed);
        $this->assertModelExists($active);
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

    private function indexDefinition(): string
    {
        return DB::selectOne(
            "select indexdef from pg_indexes where tablename = 'workflow_cases' and indexname = ?",
            [self::RECORD_UNIQUE],
        )->indexdef;
    }
}
