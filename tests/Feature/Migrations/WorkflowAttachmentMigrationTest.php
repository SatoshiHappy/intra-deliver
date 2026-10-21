<?php

namespace Tests\Feature\Migrations;

use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseDraftAttachment;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowRouteNode;
use Closure;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\TransactionalTestCase;

/**
 * 添付の列と表を足すマイグレーション 5 本(docs の 設計/ワークフロー/申請の周辺/データモデル.md の 5 節・7 節): 案件の行がある DB で
 * up が通り、案件の写しが一時保存で NULL・ほかで true になる。空の DB で down・up が通る。足した値(使わないにしたフロー・写しが false の
 * 案件・添付を追加できないノード・一時保存の添付の行)があると down が何も変えずに止まる。DDL もテストの TX の中で行い、終わりに巻き戻る。
 */
final class WorkflowAttachmentMigrationTest extends TransactionalTestCase
{
    private const MIGRATIONS = [
        '2026_10_21_100001_add_is_attachment_enabled_to_workflow_flows_table.php',
        '2026_10_21_100002_add_is_attachment_enabled_to_workflow_cases_table.php',
        '2026_10_21_100003_add_prevents_attachment_add_to_workflow_route_nodes_table.php',
        '2026_10_21_100004_add_prevents_attachment_add_to_workflow_case_nodes_table.php',
        '2026_10_21_100005_create_workflow_case_draft_attachments_table.php',
    ];

    /** 足す列(表 → 列) */
    private const ADDED_COLUMNS = [
        'workflow_flows' => 'is_attachment_enabled',
        'workflow_cases' => 'is_attachment_enabled',
        'workflow_route_nodes' => 'prevents_attachment_add',
        'workflow_case_nodes' => 'prevents_attachment_add',
    ];

    public function test_up_on_a_database_with_cases_fills_the_copy_of_the_applied_cases(): void
    {
        $cases = [
            WorkflowCase::factory()->draft()->createOne(),
            WorkflowCase::factory()->active()->createOne(),
            WorkflowCase::factory()->completed()->createOne(),
        ];
        $this->down();
        $this->assertFalse(Schema::hasColumn('workflow_cases', 'is_attachment_enabled'));

        $this->up();

        $copies = DB::table('workflow_cases')->whereIn('id', array_map(fn (WorkflowCase $case) => $case->id, $cases))
            ->orderBy('id')->pluck('is_attachment_enabled')->all();
        $this->assertSame([null, true, true], $copies);
        $this->assertSame(0, DB::table('workflow_cases')
            ->whereRaw("(state = 'draft') <> (is_attachment_enabled IS NULL)")->count());
        $this->assertFalse(DB::table('workflow_cases')->where('is_attachment_enabled', false)->exists());
    }

    public function test_down_and_up_on_an_empty_database(): void
    {
        DB::statement('TRUNCATE workflow_flows, workflow_cases CASCADE');

        $this->down();

        $this->assertSame([false, false, false, false], $this->addedColumnsExist());
        $this->assertFalse(Schema::hasTable('workflow_case_draft_attachments'));

        $this->up();

        $this->assertSame([true, true, true, true], $this->addedColumnsExist());
        $this->assertTrue(Schema::hasTable('workflow_case_draft_attachments'));
    }

    /**
     * 前の形で表せない行: 名前 → 行を入れる処理。
     *
     * @return array<string, array{Closure(): mixed}>
     */
    public static function rowsThatBlockTheDown(): array
    {
        return [
            '添付を使わないフロー' => [fn () => WorkflowFlow::factory()->createOne(['is_attachment_enabled' => false])],
            '写しが false の案件' => [
                fn () => WorkflowCase::factory()->active()->createOne(['is_attachment_enabled' => false]),
            ],
            '添付を追加できないルートのノード' => [
                fn () => WorkflowRouteNode::factory()->createOne(['position' => 2, 'prevents_attachment_add' => true]),
            ],
            '添付を追加できない案件のノード' => [
                fn () => WorkflowCaseNode::factory()->createOne(['position' => 2, 'prevents_attachment_add' => true]),
            ],
            '一時保存の添付の行' => [fn () => WorkflowCaseDraftAttachment::factory()->createOne()],
        ];
    }

    /**
     * @param  Closure(): mixed  $insert
     */
    #[DataProvider('rowsThatBlockTheDown')]
    public function test_down_stops_without_changes_while_rows_of_the_added_values_exist(Closure $insert): void
    {
        $insert();
        $ran = DB::table('migrations')->count();

        $this->assertThrows(fn () => $this->down(), RuntimeException::class, 'down を止めた');

        $this->assertSame($ran, DB::table('migrations')->count());
        $this->assertSame([true, true, true, true], $this->addedColumnsExist());
        $this->assertTrue(Schema::hasTable('workflow_case_draft_attachments'));
    }

    /**
     * @return list<bool>
     */
    private function addedColumnsExist(): array
    {
        $exists = [];

        foreach (self::ADDED_COLUMNS as $table => $column) {
            $exists[] = Schema::hasColumn($table, $column);
        }

        return $exists;
    }

    private function down(): void
    {
        $this->migrator()->reset($this->paths());
    }

    private function up(): void
    {
        $this->migrator()->run($this->paths());
    }

    /**
     * @return list<string>
     */
    private function paths(): array
    {
        return array_map(fn (string $file) => database_path("migrations/{$file}"), self::MIGRATIONS);
    }

    /**
     * Laravel のマイグレーターで、このマイグレーションだけを down・up する(パスに渡したファイルのマイグレーションだけが対象になる)。
     */
    private function migrator(): Migrator
    {
        return $this->app->make(Migrator::class);
    }
}
