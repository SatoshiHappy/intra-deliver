<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Authz\Api\CreateSelectionSubjectGroup;
use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Workflow\CreateWorkflowFlow;
use App\Actions\Workflow\RouteEdgeInput;
use App\Actions\Workflow\RouteNodeInput;
use App\Actions\Workflow\WorkflowRouteNodeInput;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Workflow\WorkflowDecisionRule;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Authz\Role;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowRouteNode;
use Carbon\CarbonImmutable;
use Tests\Support\OperatesOnRoutes;
use Tests\Support\TransactionalTestCase;

/**
 * 処理期限の日数と確認する人の運び方(docs の 設計/ワークフロー/案件の運用/データモデル/表.md の 2.3 節・3.2 節、
 * 設計/ワークフロー/案件の運用/定義の管理.md の 4 節): 定義を書く口・版の複製・申請の時の写しは 2 列をほかの列と同じに運び、
 * 展開の行は期限の日数だけ親から写す。確認する人の対象者は用途 selection(ユーザー u0003)。
 */
final class NodeDeadlineConfirmCopyTest extends TransactionalTestCase
{
    use OperatesOnRoutes;

    public function test_the_version_and_the_application_carry_the_deadline_and_the_confirmer(): void
    {
        $confirm = $this->confirmSubjectGroup();
        $versionId = $this->addVersion($this->deadlineRoute($confirm));

        [$case] = $this->applyOnRoute(['budget' => 100]);

        $expected = [
            'apply' => [null, $confirm], 'chief' => [3, $confirm], 'line' => [5, null], 'council' => [0, null],
            'final' => [null, null],
        ];
        $this->assertSame($expected, $this->routeColumns($versionId));
        $this->assertSame($expected, array_intersect_key($this->caseColumns($case), $expected));
    }

    public function test_the_children_of_the_placements_copy_only_the_deadline_of_the_parent(): void
    {
        $this->addVersion($this->deadlineRoute($this->confirmSubjectGroup()));
        [$case] = $this->applyOnRoute(['budget' => 100]);

        $this->approve($case, 'chief', 'u0002', ['line' => ['u0004'], 'council' => ['u0005', 'u0009']]);
        $this->approve($case, 'line.1', 'u0004');

        $this->assertSame(
            ['line.1' => [5, null], 'council.1' => [0, null], 'council.2' => [0, null]],
            array_intersect_key($this->caseColumns($case), array_flip(['line.1', 'council.1', 'council.2'])),
        );
    }

    public function test_the_linear_route_writes_and_the_copy_keeps_the_deadline_and_the_confirmer(): void
    {
        $confirm = $this->confirmSubjectGroup();
        $nodes = [
            new WorkflowRouteNodeInput(
                'apply',
                '申請',
                WorkflowNodeKind::Apply,
                $this->applicants(),
                ['ja' => '申請'],
                confirmSubjectGroupId: $confirm,
            ),
            $this->linearApproval('first', 'u0002', 2, $confirm),
            $this->linearApproval('second', 'u0010', 99, null),
        ];
        $contentCode = $this->projectContentCode();
        $flows = app(CreateWorkflowFlow::class);
        $start = CarbonImmutable::parse(self::FLOW_START_DATE);

        $flows->execute('wf_deadline', '期限つき', null, $contentCode, $nodes, $start, null, []);
        $flows->execute('wf_deadline_copy', '期限つきの複製', null, $contentCode, 'wf_deadline', $start, null, []);

        $expected = ['apply' => [null, $confirm], 'first' => [2, $confirm], 'second' => [99, null]];
        $this->assertSame($expected, $this->routeColumns($this->firstVersionId('wf_deadline')));
        $this->assertSame($expected, $this->routeColumns($this->firstVersionId('wf_deadline_copy')));
    }

    /**
     * 申請(確認する人)→ chief(u0002。期限 3、確認する人。指定する)→ line → council({@see self::placementNodes()})→ final(u0010)。
     *
     * @return array{list<RouteNodeInput>, list<RouteEdgeInput>}
     */
    private function deadlineRoute(int $confirm): array
    {
        return [[
            new RouteNodeInput(
                'apply',
                '申請',
                WorkflowNodeKind::Apply,
                WorkflowTargetKind::Fixed,
                $this->applicants(),
                ['ja' => '申請'],
                confirmSubjectGroupId: $confirm,
            ),
            new RouteNodeInput(
                'chief',
                'chief',
                WorkflowNodeKind::Approve,
                WorkflowTargetKind::Fixed,
                [$this->userCondition('u0002')],
                ['ja' => 'chief'],
                sendBackCodes: ['apply'],
                deadlineDays: 3,
                confirmSubjectGroupId: $confirm,
            ),
            ...$this->placementNodes(),
            $this->approveNode('final', 'u0010', ['apply', 'chief']),
        ], $this->chain(['apply', 'chief'], ['chief', 'line'], ['line', 'council'], ['council', 'final'])];
    }

    /**
     * line(横配置。期限 5)と council(縦配置。期限 0、人数 2 で可決)。どちらも chief が指定する。
     *
     * @return list<RouteNodeInput>
     */
    private function placementNodes(): array
    {
        $front = ['apply', 'chief'];

        return [
            new RouteNodeInput(
                'line',
                '順番の承認',
                WorkflowNodeKind::Serial,
                WorkflowTargetKind::Designated,
                designatorCode: 'chief',
                minAssignees: 1,
                maxAssignees: 3,
                sendBackCodes: $front,
                deadlineDays: 5,
            ),
            new RouteNodeInput(
                'council',
                '合議',
                WorkflowNodeKind::Parallel,
                WorkflowTargetKind::Designated,
                designatorCode: 'chief',
                minAssignees: 2,
                maxAssignees: 3,
                decisionRule: WorkflowDecisionRule::Count,
                decisionValue: 2,
                sendBackCodes: $front,
                deadlineDays: 0,
            ),
        ];
    }

    /**
     * 一本道のルートの承認のノード(処理対象者 = ユーザー 1 人)。
     */
    private function linearApproval(string $code, string $userCode, int $days, ?int $confirm): WorkflowRouteNodeInput
    {
        $users = [$this->userCondition($userCode)];
        $names = ['ja' => $code];

        return new WorkflowRouteNodeInput($code, $code, WorkflowNodeKind::Approve, $users, $names, $days, $confirm);
    }

    /**
     * @return list<SelectionCondition> 申請の処理対象者(一般ユーザーのロール)
     */
    private function applicants(): array
    {
        $roleId = Role::query()->where('code', 'general_user')->sole()->id;

        return [new SelectionCondition(SelectionConditionKind::Role, $roleId)];
    }

    private function confirmSubjectGroup(): int
    {
        return app(CreateSelectionSubjectGroup::class)->execute([$this->userCondition('u0003')], ['ja' => '確認する人']);
    }

    /**
     * @return array<string, array{int|null, int|null}> ノードのコード → [期限の日数, 確認する人]
     */
    private function routeColumns(int $versionId): array
    {
        return WorkflowRouteNode::query()->where('workflow_flow_version_id', $versionId)->orderBy('position')->get()
            ->mapWithKeys(fn (WorkflowRouteNode $node) => [
                $node->code => [$node->deadline_days, $node->confirm_authz_subject_group_id],
            ])
            ->all();
    }

    /**
     * @return array<string, array{int|null, int|null}> ノードの名札 → [期限の日数, 確認する人]
     */
    private function caseColumns(WorkflowCase $case): array
    {
        $columns = [];

        foreach (WorkflowCaseNode::query()->where('workflow_case_id', $case->id)->get() as $node) {
            $columns[$node->id] = [$node->deadline_days, $node->confirm_authz_subject_group_id];
        }

        return array_map(fn (int $id) => $columns[$id], $this->labelIds($case));
    }

    private function firstVersionId(string $flowCode): int
    {
        $flowId = WorkflowFlow::query()->where('code', $flowCode)->sole()->id;

        return WorkflowFlowVersion::query()->where('workflow_flow_id', $flowId)->where('number', 1)->sole()->id;
    }
}
