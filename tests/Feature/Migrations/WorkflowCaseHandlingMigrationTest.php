<?php

namespace Tests\Feature\Migrations;

use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use Closure;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\OperatesOnRoutes;
use Tests\Support\TransactionalTestCase;

/**
 * 案件の運用のマイグレーション 7 本(docs の 設計/ワークフロー/案件の運用/データモデル/README.md の 5 節・7 節): 前の形の案件の行がある DB で
 * up が通り、一時保存でない案件のすべてのノードの到達日時と、到達を起こした行からの前処理者・起点の組織が埋まる。マイグレーションの前の差戻し・
 * 合議の可決を後で引戻しても検査制約に反しない。空の DB で down・up が通る。保留・実行者・足した種別の行があると down が何も変えずに止まる。
 * 差戻しの行(後ろからの到達)からは前処理者を埋めない。置き換える検査制約が無いと up が止まる。
 * 前の形の行は、今の業務処理で作ってから down で列を消して作る。DDL もテストの TX の中で行い、終わりに巻き戻る。
 */
final class WorkflowCaseHandlingMigrationTest extends TransactionalTestCase
{
    use OperatesOnRoutes;

    private const MIGRATIONS = [
        '2026_10_19_300001_add_deadline_days_confirm_authz_subject_group_id_to_workflow_route_nodes_table.php',
        '2026_10_19_300002_add_reached_at_previous_and_hold_to_workflow_case_nodes_table.php',
        '2026_10_19_300003_add_executor_and_actions_to_workflow_case_histories_table.php',
        '2026_10_19_300004_create_workflow_case_history_users_table.php',
        '2026_10_19_300005_create_workflow_case_confirm_targets_table.php',
        '2026_10_19_300006_create_workflow_case_confirmations_table.php',
        '2026_10_19_300007_create_workflow_proxies_table.php',
    ];

    /** 足す表 */
    private const ADDED_TABLES = [
        'workflow_case_history_users', 'workflow_case_confirm_targets', 'workflow_case_confirmations',
        'workflow_proxies',
    ];

    /** 足した履歴の種別(前の形で表せない = down が止まる種別。マイグレーションの down の止める条件と同じ) */
    private const ADDED_ACTIONS = [
        'hold', 'release', 'transfer', 'retarget', 'reexpand', 'move_back', 'move_forward', 'move_finish', 'reassign',
    ];

    /**
     * 初期データの見本のうち前の形で表せない行を持つ案件(保留・申請の代理・担当変更 = 設計/ワークフロー/案件の運用/初期データ.md 4.3)を
     * TX の中で消してから始める(残すと、どのテストの down もその行で止まり、止まる筋のテストが自分の入れた行で止まることを確かめなくなる)。
     */
    protected function setUp(): void
    {
        parent::setUp();

        $held = DB::table('workflow_case_nodes')->where('state', 'hold')->pluck('workflow_case_id');
        $added = DB::table('workflow_case_histories')->whereIn('action', self::ADDED_ACTIONS)
            ->orWhereNotNull('executor_user_id')->pluck('workflow_case_id');
        WorkflowCase::query()->whereKey([...$held, ...$added])->delete();
    }

    public function test_up_on_a_database_with_cases_fills_the_reached_time_and_the_previous_processor(): void
    {
        [$sentBack, $council] = $this->casesMadeBeforeTheMigration();
        $this->down();
        $this->assertFalse(Schema::hasColumn('workflow_case_nodes', 'reached_at'));

        $this->up();

        $this->assertSame(0, DB::table('workflow_case_nodes')
            ->join('workflow_cases', 'workflow_cases.id', '=', 'workflow_case_nodes.workflow_case_id')
            ->where('workflow_cases.state', '<>', 'draft')->whereNull('workflow_case_nodes.reached_at')->count());
        $applicant = $this->seededUser('u0001')->id;
        $this->assertSame([$applicant, $sentBack->department_id], $this->previousOf($sentBack, 'section_chief'));
        $this->assertSame([$this->seededUser('u0002')->id, null], $this->previousOf($council, 'council.3'));
        $this->assertSame([null, null], $this->previousOf($council, 'apply'));
    }

    public function test_up_does_not_fill_the_previous_processor_from_a_send_back_row(): void
    {
        [$sentBack] = $this->casesMadeBeforeTheMigration();
        $childId = $this->childListedInTheSendBack($sentBack);
        $this->down();

        $this->up();

        $child = DB::table('workflow_case_nodes')->where('id', $childId)->sole();
        $this->assertSame([null, null], [$child->previous_user_id, $child->previous_department_id]);
        $applicant = $this->seededUser('u0001')->id;
        $this->assertSame([$applicant, $sentBack->department_id], $this->previousOf($sentBack, 'section_chief'));
    }

    public function test_up_stops_without_adding_columns_when_a_constraint_to_replace_is_missing(): void
    {
        $this->down();
        DB::statement('ALTER TABLE workflow_case_nodes DROP CONSTRAINT workflow_case_nodes_state_check');

        $this->assertThrows(fn () => $this->up(), QueryException::class);

        $this->assertFalse(Schema::hasColumn('workflow_case_nodes', 'reached_at'));
    }

    public function test_pulling_back_a_send_back_or_a_council_decision_made_before_the_migration_passes(): void
    {
        [$sentBack, $council] = $this->casesMadeBeforeTheMigration();
        $this->down();
        $this->up();

        $this->pullBackOn($sentBack, 'council.1', 'u0004');
        $this->pullBackOn($council, 'council.2', 'u0005');

        $this->assertSame(['waiting[u0004]', 'waiting[u0009]'], $this->states($sentBack, 'council.1', 'council.3'));
        $this->assertSame(['running', 'waiting[u0009]'], $this->states($council, 'council', 'council.3'));
    }

    public function test_down_and_up_on_an_empty_database(): void
    {
        DB::statement('TRUNCATE workflow_flows, workflow_cases CASCADE');

        $this->down();

        $this->assertSame([false, false, false, false], $this->addedTablesExist());
        $this->assertSame([false, false, false], [
            Schema::hasColumn('workflow_route_nodes', 'deadline_days'),
            Schema::hasColumn('workflow_case_nodes', 'reached_at'),
            Schema::hasColumn('workflow_case_histories', 'executor_user_id'),
        ]);
        $this->assertStringNotContainsString("'hold'", $this->checkDefinition('workflow_case_nodes_kind_state_check'));
        $this->assertStringNotContainsString("'hold'", $this->checkDefinition('workflow_case_histories_action_check'));

        $this->up();

        $this->assertSame([true, true, true, true], $this->addedTablesExist());
        $this->assertStringContainsString("'hold'", $this->checkDefinition('workflow_case_nodes_kind_state_check'));
    }

    /**
     * 前の形で表せない行: 名前 → 行を入れる処理。
     *
     * @return array<string, array{Closure(self): mixed}>
     */
    public static function rowsThatBlockTheDown(): array
    {
        return [
            '保留のノード' => [fn (self $test) => $test->heldNode()],
            '実行者だけを持つ承認の行' => [fn (self $test) => $test->approvalByProxy()],
            '足した種別の行' => [fn (self $test) => $test->transfer()],
        ];
    }

    /**
     * @param  Closure(self): mixed  $insert
     */
    #[DataProvider('rowsThatBlockTheDown')]
    public function test_down_stops_without_changes_while_rows_of_the_added_values_exist(Closure $insert): void
    {
        $insert($this);
        $ran = DB::table('migrations')->count();

        $this->assertThrows(fn () => $this->down(), RuntimeException::class, 'down を止めた');

        $this->assertSame($ran, DB::table('migrations')->count());
        $this->assertSame([true, true, true, true], $this->addedTablesExist());
        $this->assertTrue(Schema::hasColumn('workflow_case_nodes', 'reached_at'));
    }

    public function heldNode(): void
    {
        WorkflowCaseNode::factory()->createOne(['position' => 2, 'state' => WorkflowNodeState::Hold]);
    }

    public function approvalByProxy(): void
    {
        WorkflowCaseHistory::factory()->byProxy()->createOne(['action' => WorkflowAction::Approve]);
    }

    public function transfer(): void
    {
        WorkflowCaseHistory::factory()->createOne(['action' => WorkflowAction::Transfer]);
    }

    /**
     * 今の業務処理で作る 2 つの案件(R1、予算 100、合議は u0004・u0005・u0009 で人数 2 で可決、責任者 u0010):
     * 合議の子 1 が課長へ差戻した案件と、合議の子 1・2 の承認で可決した案件。
     *
     * @return array{WorkflowCase, WorkflowCase}
     */
    private function casesMadeBeforeTheMigration(): array
    {
        $this->addVersion($this->r1());
        $sentBack = $this->councilCase();
        $this->approve($sentBack, 'council.2', 'u0005');
        $this->sendBackOn($sentBack, 'council.1', 'u0004', 'section_chief');
        $council = $this->councilCase();
        $this->approve($council, 'council.1', 'u0004');
        $this->approve($council, 'council.2', 'u0005');

        return [$sentBack, $council];
    }

    /**
     * 差戻しの行の node_changes に、前の状態 = 到達していないで載る合議の子を足す(前の形の行。今の業務処理は差戻しで子を使い直すので作らない)。
     *
     * @return int 足した子の id
     */
    private function childListedInTheSendBack(WorkflowCase $case): int
    {
        $child = WorkflowCaseNode::query()->create([
            'workflow_case_id' => $case->id, 'name' => '承認 4', 'kind' => WorkflowNodeKind::Approve,
            'parent_workflow_case_node_id' => $this->labelIds($case)['council'], 'sequence' => 4,
            'prevents_applicant_approval' => true, 'state' => WorkflowNodeState::Unreached,
        ]);
        $change = ['node' => $child->id, 'state' => 'unreached', 'history' => null, 'targets' => []];
        DB::update(
            'UPDATE workflow_case_histories SET node_changes = node_changes || ?::jsonb'
            ." WHERE workflow_case_id = ? AND action = 'send_back'",
            [json_encode([$change], JSON_THROW_ON_ERROR), $case->id],
        );

        return $child->id;
    }

    private function councilCase(): WorkflowCase
    {
        [$case] = $this->applyOnRoute(['budget' => 100]);
        $this->approve($case, 'section_chief', 'u0002', [
            'council' => ['u0004', 'u0005', 'u0009'],
            'owner' => ['u0010'],
        ]);

        return $case;
    }

    /**
     * @return array{int|null, int|null}
     */
    private function previousOf(WorkflowCase $case, string $label): array
    {
        $node = DB::table('workflow_case_nodes')->where('id', $this->labelIds($case)[$label])->sole();

        return [$node->previous_user_id, $node->previous_department_id];
    }

    /**
     * @return list<string>
     */
    private function states(WorkflowCase $case, string ...$labels): array
    {
        $shape = $this->shape($case);

        return array_map(fn (string $label) => $shape[$label], array_values($labels));
    }

    /**
     * @return list<bool>
     */
    private function addedTablesExist(): array
    {
        return array_map(fn (string $table) => Schema::hasTable($table), self::ADDED_TABLES);
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

    private function migrator(): Migrator
    {
        return $this->app->make(Migrator::class);
    }

    private function checkDefinition(string $name): string
    {
        return (string) DB::scalar('select pg_get_constraintdef(oid) from pg_constraint where conname = ?', [$name]);
    }
}
