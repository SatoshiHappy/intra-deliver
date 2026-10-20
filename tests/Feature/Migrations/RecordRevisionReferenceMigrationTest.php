<?php

namespace Tests\Feature\Migrations;

use App\Models\Workflow\WorkflowCaseHistory;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\TransactionalTestCase;

/**
 * 履歴の値の写しを更新履歴の版の参照に付け替えるマイグレーション(docs の 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の
 * 4.2 節、同じ階層の データモデル/README.md の 5 節): 申請の行の無い DB で down と up が通り、版を指す行があると down が何も変えずに止まる。
 * DDL もテストの TX の中で行い、終わりに巻き戻る。
 */
final class RecordRevisionReferenceMigrationTest extends TransactionalTestCase
{
    private const MIGRATION = '2026_10_19_400009_replace_record_snapshot_with_record_revision_id'
        .'_in_workflow_case_histories_table.php';

    private const REVISION_CHECK = 'workflow_case_histories_action_record_revision_id_check';

    public function test_down_and_up_on_a_database_without_applications(): void
    {
        DB::statement('TRUNCATE workflow_flows, workflow_cases CASCADE');

        $this->migrator()->reset([$this->path()]);

        $this->assertSame([true, false], [
            Schema::hasColumn('workflow_case_histories', 'record_snapshot'),
            Schema::hasColumn('workflow_case_histories', 'record_revision_id'),
        ]);
        $this->assertSame([
            'workflow_case_histories_action_record_snapshot_check',
            'workflow_case_histories_record_snapshot_check',
        ], $this->snapshotAndRevisionChecks());

        $this->migrator()->run([$this->path()]);

        $this->assertSame([false, true], [
            Schema::hasColumn('workflow_case_histories', 'record_snapshot'),
            Schema::hasColumn('workflow_case_histories', 'record_revision_id'),
        ]);
        $this->assertSame([self::REVISION_CHECK], $this->snapshotAndRevisionChecks());
    }

    public function test_down_stops_without_changes_while_a_row_points_at_a_revision(): void
    {
        WorkflowCaseHistory::factory()->createOne();
        $ran = DB::table('migrations')->count();

        $this->assertThrows(fn () => $this->migrator()->reset([$this->path()]), RuntimeException::class, 'down を止めた');

        $this->assertSame($ran, DB::table('migrations')->count());
        $this->assertSame([false, true], [
            Schema::hasColumn('workflow_case_histories', 'record_snapshot'),
            Schema::hasColumn('workflow_case_histories', 'record_revision_id'),
        ]);
        $this->assertSame([self::REVISION_CHECK], $this->snapshotAndRevisionChecks());
    }

    /**
     * Laravel のマイグレーターで、このマイグレーションだけを down・up する(パスに渡したファイルのマイグレーションだけが対象になる)。
     */
    private function migrator(): Migrator
    {
        return $this->app->make(Migrator::class);
    }

    private function path(): string
    {
        return database_path('migrations/'.self::MIGRATION);
    }

    /**
     * 履歴の写しと版の参照の検査制約の名前(名前の順)。
     *
     * @return list<string>
     */
    private function snapshotAndRevisionChecks(): array
    {
        return array_column(DB::select(
            "select conname from pg_constraint where conrelid = 'workflow_case_histories'::regclass and contype = 'c'"
            ." and (conname like '%record_snapshot%' or conname like '%record_revision_id%') order by conname",
        ), 'conname');
    }
}
