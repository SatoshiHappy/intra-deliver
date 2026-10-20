<?php

namespace Tests\Feature\Models\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseAssignment;
use App\Models\Workflow\WorkflowCaseEdge;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowCaseNodeTarget;
use App\Models\Workflow\WorkflowCaseSendBack;
use App\Models\Workflow\WorkflowContent;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowRouteEdge;
use App\Models\Workflow\WorkflowRouteNode;
use App\Models\Workflow\WorkflowRouteSendBack;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 削除の向き(docs の 設計/ワークフロー/データモデル/表.md 2.9、直列化と検査.md 5 節・6 節の「削除」と、段 3a の ルートの深さ/データモデル/案件の表.md 3.8、
 * 直列化と検査.md 7 節の「削除」): 一時保存の案件は消せる、案件が指していない版を消すとノード・辺・差戻しできる先が連鎖で消える(ノードどうし・辺がノードを
 * RESTRICT で指していても)、フローを消すと版も消える、ワークフローの行が指す行(版・コンテンツ・フロー・レコード・業務モジュール・対象者・役職・ユーザー・組織)は
 * 消せない、申請した案件を消すとノード・辺・差戻しできる先・指定・処理対象者・履歴が連鎖で全部消える(互いを RESTRICT で指していても)。
 * 外部キーごとの削除の動作の照合は tests/Feature/Models/ForeignKeysTest.php。ここは業務の場面ごとに確かめる。
 */
final class WorkflowDeletionTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    /**
     * 指す側のモデル・factory の状態・指す列・指される表・拒む外部キー。
     *
     * @return array<string, array{class-string<Model>, string|null, string, string, string}>
     */
    public static function pointedRows(): array
    {
        return [
            'フローが指すコンテンツ' => [WorkflowFlow::class, null, 'workflow_content_id', 'workflow_contents', 'workflow_flows_workflow_content_id_foreign'],
            '案件が指すフロー' => [WorkflowCase::class, null, 'workflow_flow_id', 'workflow_flows', 'workflow_cases_workflow_flow_id_foreign'],
            '案件が指す版' => [WorkflowCase::class, 'active', 'workflow_flow_version_id', 'workflow_flow_versions', 'workflow_cases_workflow_flow_id_workflow_flow_version_id_foreign'],
            '案件が指すレコード' => [WorkflowCase::class, 'active', 'record_id', 'records', 'workflow_cases_record_id_foreign'],
            'コンテンツが指す業務モジュール' => [WorkflowContent::class, null, 'record_module_id', 'record_modules', 'workflow_contents_record_module_id_is_detail_foreign'],
            'ルートのノードが指す対象者' => [WorkflowRouteNode::class, null, 'authz_subject_group_id', 'authz_subject_groups', 'workflow_route_nodes_authz_subject_group_id_purpose_foreign'],
            'フローが指す対象者(参照者)' => [WorkflowFlow::class, 'withReferrers', 'authz_subject_group_id', 'authz_subject_groups', 'workflow_flows_authz_subject_group_id_purpose_foreign'],
            '案件のノードが指す対象者' => [WorkflowCaseNode::class, null, 'authz_subject_group_id', 'authz_subject_groups', 'workflow_case_nodes_authz_subject_group_id_purpose_foreign'],
            'ルートのノードが指す役職' => [WorkflowRouteNode::class, 'relative', 'company_post_id', 'company_posts', 'workflow_route_nodes_company_post_id_foreign'],
            '案件のノードが指す役職' => [WorkflowCaseNode::class, 'relative', 'company_post_id', 'company_posts', 'workflow_case_nodes_company_post_id_foreign'],
            '指定した人' => [WorkflowCaseAssignment::class, null, 'user_id', 'users', 'workflow_case_assignments_user_id_foreign'],
            '申請者' => [WorkflowCase::class, null, 'user_id', 'users', 'workflow_cases_user_id_foreign'],
            '担当組織' => [WorkflowCase::class, 'active', 'department_id', 'departments', 'workflow_cases_department_id_foreign'],
            '処理対象者' => [WorkflowCaseNodeTarget::class, null, 'user_id', 'users', 'workflow_case_node_targets_user_id_foreign'],
            '処理者' => [WorkflowCaseHistory::class, null, 'user_id', 'users', 'workflow_case_histories_user_id_foreign'],
        ];
    }

    public function test_deletes_a_draft_case(): void
    {
        $draft = WorkflowCase::factory()->draft()->create();

        $draft->delete();

        $this->assertModelMissing($draft);
    }

    public function test_deleting_a_version_that_no_case_uses_deletes_its_nodes_edges_and_send_backs(): void
    {
        $version = $this->versionWithRoute();

        $version->delete();

        $this->assertSame([0, 0, 0], $this->routeCounts($version->id));
    }

    public function test_deleting_a_flow_that_no_case_uses_deletes_its_versions(): void
    {
        $version = $this->versionWithRoute();

        WorkflowFlow::query()->whereKey($version->workflow_flow_id)->delete();

        $this->assertModelMissing($version);
        $this->assertSame([0, 0, 0], $this->routeCounts($version->id));
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('pointedRows')]
    public function test_rejects_deleting_a_row_that_a_workflow_row_points_to(string $model, ?string $state, string $column, string $table, string $constraint): void
    {
        $factory = Factory::factoryForModel($model);
        $row = ($state === null ? $factory : $factory->{$state}())->createOne();

        $this->assertRejectedBy($constraint, fn () => DB::table($table)->where('id', $row->getAttribute($column))->delete());
    }

    public function test_deleting_an_applied_case_deletes_its_nodes_histories_and_targets(): void
    {
        $case = WorkflowCase::factory()->active()->create();
        $apply = WorkflowCaseNode::factory()->done()->create(['workflow_case_id' => $case->id]);
        $approve = WorkflowCaseNode::factory()->done()->create(['workflow_case_id' => $case->id]);
        WorkflowCaseHistory::factory()->create(['workflow_case_node_id' => $approve->id, 'action' => WorkflowAction::SendBack, 'send_back_workflow_case_node_id' => $apply->id]);
        $current = WorkflowCaseNode::factory()->current()->create(['workflow_case_id' => $case->id]);
        WorkflowCaseNodeTarget::factory()->count(2)->create(['workflow_case_node_id' => $current->id]);
        WorkflowCaseHistory::factory()->pullBack()->create(['workflow_case_node_id' => $approve->id]);
        WorkflowCaseEdge::factory()->create(['workflow_case_id' => $case->id, 'from_workflow_case_node_id' => $apply->id, 'to_workflow_case_node_id' => $approve->id]);
        WorkflowCaseSendBack::factory()->create(['workflow_case_id' => $case->id, 'workflow_case_node_id' => $approve->id, 'target_workflow_case_node_id' => $apply->id]);
        WorkflowCaseAssignment::factory()->create(['workflow_case_id' => $case->id]);
        WorkflowCaseNode::factory()->child()->create(['workflow_case_id' => $case->id]);
        WorkflowCaseNode::factory()->branchStart()->create(['workflow_case_id' => $case->id]);

        $case->delete();

        $this->assertModelMissing($case);
        $this->assertSame(
            [0, 0, 0, 0, 0],
            array_map(fn (string $model): int => $model::query()->where('workflow_case_id', $case->id)->count(), [WorkflowCaseNode::class, WorkflowCaseHistory::class, WorkflowCaseEdge::class, WorkflowCaseSendBack::class, WorkflowCaseAssignment::class]),
        );
        $this->assertSame(0, WorkflowCaseNodeTarget::query()->where('workflow_case_node_id', $current->id)->count());
    }

    /**
     * 案件の指さない版と、その一本道のルート(申請 → 承認 → 処理の時に指定(指定するノード = 承認)・分岐開始(対の終了)、辺、差戻しできる先)。
     */
    private function versionWithRoute(): WorkflowFlowVersion
    {
        $version = WorkflowFlowVersion::factory()->createOne();
        $apply = WorkflowRouteNode::factory()->createOne(['workflow_flow_version_id' => $version->id, 'position' => 1]);
        $approve = WorkflowRouteNode::factory()->createOne(['workflow_flow_version_id' => $version->id, 'position' => 2]);
        WorkflowRouteNode::factory()->designated()->create(['workflow_flow_version_id' => $version->id]);
        WorkflowRouteNode::factory()->branchStart()->create(['workflow_flow_version_id' => $version->id]);
        WorkflowRouteEdge::factory()->create(['workflow_flow_version_id' => $version->id, 'from_workflow_route_node_id' => $apply->id, 'to_workflow_route_node_id' => $approve->id]);
        WorkflowRouteSendBack::factory()->create(['workflow_flow_version_id' => $version->id, 'workflow_route_node_id' => $approve->id, 'target_workflow_route_node_id' => $apply->id]);

        $this->assertSame(WorkflowNodeKind::Apply, $apply->kind);
        $this->assertSame([6, 1, 1], $this->routeCounts($version->id));

        return $version;
    }

    /**
     * 版のノード・辺・差戻しできる先の行数。
     *
     * @return list<int>
     */
    private function routeCounts(int $versionId): array
    {
        return array_map(fn (string $model): int => $model::query()->where('workflow_flow_version_id', $versionId)->count(), [WorkflowRouteNode::class, WorkflowRouteEdge::class, WorkflowRouteSendBack::class]);
    }
}
