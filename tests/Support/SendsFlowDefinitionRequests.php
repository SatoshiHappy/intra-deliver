<?php

namespace Tests\Support;

use App\Models\Authz\Role;
use App\Models\Master\Department;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowRouteNode;
use App\Models\Workflow\WorkflowRouteSendBack;
use App\Queries\Authz\Api\SelectionSubjects;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * 定義の管理の 8 ルート(docs の 設計/ワークフロー/ルートの深さ/画面/README.md 1.1 節)を、初期データの「プロジェクト計画の承認」
 * (版 1 = ユーザー無効、版 2、版 3 = 分岐 B = ルートの深さ/初期データ.md 3 節)に送るテストの手助け。システム管理者 = u0004。
 */
trait SendsFlowDefinitionRequests
{
    /** 初期データのフロー */
    protected const PLAN = 'project_plan';

    /** システム管理者 */
    protected const ADMIN = 'u0004';

    /**
     * ルートの名前 → [メソッド, URL, 正しい入力](書き込みは成功する入力。引数はプロジェクト計画の承認)。
     *
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    protected function definitionRoutes(string $flow = self::PLAN): array
    {
        $base = "/workflow/admin/flows/{$flow}";

        return [
            'workflow.admin.flows.index' => ['get', '/workflow/admin/flows', []],
            'workflow.admin.flows.show' => ['get', $base, []],
            'workflow.admin.flows.referrers.update' => ['put', "{$base}/referrers", $this->referrersInput()],
            'workflow.admin.versions.store' => ['post', "{$base}/versions", ['start_date' => $this->tomorrow()]],
            'workflow.admin.versions.disable' => ['post', "{$base}/versions/2/disable", []],
            'workflow.admin.versions.enable' => ['post', "{$base}/versions/1/enable", []],
            'workflow.admin.nodes.update' => [
                'put', "{$base}/versions/3/nodes/section_chief",
                $this->nodeInput(3, 'section_chief', ['prevents_applicant_approval' => false]),
            ],
            'workflow.admin.subject-candidates' => ['get', '/workflow/admin/subject-candidates?type=user&q=u000', []],
        ];
    }

    /**
     * JSON で送る(422 の中身を読むため)。
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    protected function sendJson(string $method, string $url, array $data = []): TestResponse
    {
        return $this->json(strtoupper($method), $url, $data);
    }

    /**
     * 参照者の正しい入力(経理部と一般ユーザーのロール)。
     *
     * @return array{referrers: list<array{type: string, id: int}>}
     */
    protected function referrersInput(): array
    {
        return ['referrers' => [
            ['type' => 'department', 'id' => Department::query()->where('code', 'accounting')->sole()->id],
            ['type' => 'role', 'id' => Role::query()->where('code', 'general_user')->sole()->id],
        ]];
    }

    /**
     * 版のノードの今の設定を入力の欄(snake_case)にし、$overrides を重ねる。
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function nodeInput(int $number, string $code, array $overrides = []): array
    {
        $node = $this->planNode($number, $code);
        $codes = WorkflowRouteNode::query()->where('workflow_flow_version_id', $node->workflow_flow_version_id)
            ->pluck('code', 'id')->all();
        $sendBacks = WorkflowRouteSendBack::query()->where('workflow_route_node_id', $node->id)->orderBy('id')
            ->pluck('target_workflow_route_node_id')->all();
        $group = (int) $node->authz_subject_group_id;
        $today = CarbonImmutable::today(AccessContext::TIMEZONE);
        $conditions = $group === 0 ? [] : app(SelectionSubjects::class)->conditionsOf([$group], $today, 'ja');
        $targets = $conditions[$group]['conditions'] ?? null;
        $row = fn (array $condition) => ['type' => $condition['kind']->value, 'id' => $condition['id']];

        return [
            'target_kind' => $node->target_kind?->value,
            'targets' => $targets === null ? null : array_map($row, $targets),
            'relative_base' => $node->relative_base?->value, 'relative_scope' => $node->relative_scope?->value,
            'company_post_id' => $node->company_post_id,
            'designator_node_code' => $codes[$node->designator_workflow_route_node_id] ?? null,
            'min_assignees' => $node->min_assignees, 'max_assignees' => $node->max_assignees,
            'prevents_applicant_approval' => $node->prevents_applicant_approval,
            'send_back_node_codes' => array_values(array_map(fn (mixed $id) => $codes[$id], $sendBacks)),
            'decision_rule' => $node->decision_rule?->value, 'decision_value' => $node->decision_value,
            ...$overrides,
        ];
    }

    protected function planNode(int $number, string $code): WorkflowRouteNode
    {
        return WorkflowRouteNode::query()->where('workflow_flow_version_id', $this->planVersion($number)->id)
            ->where('code', $code)->sole();
    }

    protected function planVersion(int $number): WorkflowFlowVersion
    {
        $flowId = WorkflowFlow::query()->where('code', self::PLAN)->sole()->id;

        return WorkflowFlowVersion::query()->where('workflow_flow_id', $flowId)->where('number', $number)->sole();
    }

    /**
     * 拒否で何も変わらないことを比べる写し(版・フローの参照者・ノードの列・差戻しできる先)。
     *
     * @return array<string, mixed>
     */
    protected function definitionSnapshot(): array
    {
        $rows = fn (string $model) => $model::query()->orderBy('id')->get()
            ->map(fn ($row) => $row->getAttributes())->all();

        return [
            'versions' => $rows(WorkflowFlowVersion::class),
            'flows' => WorkflowFlow::query()->orderBy('id')->pluck('authz_subject_group_id')->all(),
            'nodes' => $rows(WorkflowRouteNode::class),
            'send_backs' => $rows(WorkflowRouteSendBack::class),
        ];
    }

    protected function seededAccount(string $code): User
    {
        return User::query()->where('code', $code)->sole();
    }

    protected function tomorrow(): string
    {
        return CarbonImmutable::today(AccessContext::TIMEZONE)->addDay()->toDateString();
    }
}
