<?php

namespace Tests\Feature\Seeders;

use App\Actions\Records\Api\RecordInput;
use App\Actions\Workflow\ApplicationInput;
use App\Actions\Workflow\ApplyCase;
use App\Models\Master\Department;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseAssignment;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowRouteEdge;
use App\Models\Workflow\WorkflowRouteNode;
use App\Models\Workflow\WorkflowRouteSendBack;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Database\Seeders\SeedingDates;
use Database\Seeders\WorkflowSeeder;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * ワークフローの初期データのうちルートの深さで足したもの(docs の 設計/ワークフロー/ルートの深さ/初期データ.md 3.2・4 節・7 節の検査): 版 3(分岐 B)のノード 7 と辺 7、
 * 足した見本 4(分岐の両方の枝・合議の途中・可決・可決できなくなった・指定の記録)、案件の申請基準日と版、u0002 の版 3 の申請が申請者承認防止で 422 になること。
 * 期待値は設計の表から書く。初期データは DatabaseSeeder が 1 回入れたもの(流し直さない)。
 */
final class WorkflowSeederRouteDepthTest extends TransactionalTestCase
{
    use CatchesValidationErrors;

    /**
     * 版 3 のノード(位置・コード・名前・種類・指定の種類・起点(基準・範囲・役職)・指定するノード・人数・可決の条件・申請者承認防止・差戻しできる先)と辺
     * (両端・並び・名前・条件)が 初期データ.md 3.2 のとおり。条件は jsonb の列の値(キーの並びは jsonb の並び)。
     */
    public function test_seeds_the_branch_route_of_version_3(): void
    {
        $version = WorkflowFlowVersion::query()->whereRelation('workflowFlow', 'code', 'project_plan')->where('number', 3)->sole();
        $nodes = WorkflowRouteNode::query()->with(['companyPost', 'designator'])->where('workflow_flow_version_id', $version->id)->orderBy('position')->get();
        $codes = $nodes->pluck('code', 'id');
        $positions = $nodes->pluck('position', 'id');
        $sendBacks = WorkflowRouteSendBack::query()->where('workflow_flow_version_id', $version->id)->get()->groupBy('workflow_route_node_id');

        $this->assertSame([
            [1, 'apply', '申請', 'apply', 'fixed', null, null, null, null, null, null, false, []],
            [2, 'section_chief', '課長', 'approve', 'relative', 'applicant own section-chief', null, null, null, null, null, true, ['apply']],
            [3, 'budget', '予算', 'branch_start', null, null, null, null, null, null, null, false, []],
            [4, 'manager', '部長', 'approve', 'relative', 'applicant parent manager', null, null, null, null, null, true, ['apply', 'section_chief']],
            [5, 'budget_end', '分岐終了', 'branch_end', null, null, null, null, null, null, null, false, []],
            [6, 'council', '関係部署の合議', 'parallel', 'designated', null, 'section_chief', 2, 5, 'count', 2, true, ['apply', 'section_chief']],
            [7, 'owner', '責任者', 'approve', 'designated', null, 'section_chief', 1, 1, null, null, true, ['apply', 'section_chief']],
        ], array_values($nodes->map(fn (WorkflowRouteNode $node) => [
            $node->position, $node->code, $node->name, $node->kind->value, $node->target_kind?->value,
            $node->relative_base === null ? null : "{$node->relative_base->value} {$node->relative_scope?->value} {$node->companyPost?->code}",
            $node->designator?->code, $node->min_assignees, $node->max_assignees, $node->decision_rule?->value, $node->decision_value, $node->prevents_applicant_approval,
            array_values(array_map(fn (WorkflowRouteSendBack $row) => $codes[$row->target_workflow_route_node_id], $sendBacks->get($node->id)?->sortBy(fn (WorkflowRouteSendBack $row) => $positions[$row->target_workflow_route_node_id])->all() ?? [])),
        ])->all()));
        $this->assertSame([
            ['apply', 'section_chief', 0, null, null],
            ['section_chief', 'budget', 0, null, null],
            ['budget', 'manager', 1, '予算 300 万円以上', ['all' => [['op' => 'ge', 'field' => 'budget', 'value' => 300]]]],
            ['budget', 'budget_end', 2, '何もしない', ['all' => [['op' => 'lt', 'field' => 'budget', 'value' => 300]]]],
            ['manager', 'budget_end', 0, null, null],
            ['budget_end', 'council', 0, null, null],
            ['council', 'owner', 0, null, null],
        ], array_values(WorkflowRouteEdge::query()->where('workflow_flow_version_id', $version->id)->orderBy('id')->get()->map(fn (WorkflowRouteEdge $edge) => [
            $codes[$edge->from_workflow_route_node_id], $codes[$edge->to_workflow_route_node_id], $edge->sort_key, $edge->label, $edge->condition,
        ])->all()));
    }

    /**
     * 案件の申請基準日と版(初期データ.md 4 節・設計/ワークフロー/案件の運用/初期データ.md 4.2): 2 段目の計画の見本 = 版 2 の日(DATES の 1 か所)、
     * 課題の見本 = 申請の操作の日(版 1。WF-000002 = D − 6、WF-000006 = D − 30、WF-000007 = D − 25、WF-000015 = D − 5、WF-000016 = D − 6)、
     * 版 3 の見本 = 流す日。
     */
    public function test_the_cases_are_applied_on_the_base_dates_of_the_design(): void
    {
        $seedingDay = SeedingDates::BASE;
        $plan = WorkflowSeeder::DATES['stage2_plan_base_date'];
        $version3 = [$seedingDay, 3];

        $this->assertSame(
            [[$plan, 2], ['2026-10-09', 1], [$plan, 2], [$plan, 2], [$plan, 2], ['2026-09-15', 1], ['2026-09-20', 1], [$plan, 2],
                $version3, $version3, $version3, $version3, $version3, $version3, ['2026-10-10', 1], ['2026-10-09', 1]],
            WorkflowCase::query()->with('workflowFlowVersion')->whereNotNull('case_number')->orderBy('case_number')->get()
                ->map(fn (WorkflowCase $case) => [$case->base_date?->toDateString(), $case->workflowFlowVersion?->number])->all(),
        );
    }

    /**
     * 足した見本の分岐と合議: WF-000009・000011 は部長を通り(処理済み)、WF-000010・000012 は部長を省く。WF-000009 は合議の途中(子 1 = u0009 が承認、子 2・3 が承認待ち。
     * 1 人が承認・必要 2 人)、WF-000011 は 2 人の否認で可決できなくなり u0016 の子は省略、WF-000012 は子 1・2 の承認で可決して責任者が承認した。
     */
    public function test_the_added_samples_take_both_branches_and_the_council_outcomes(): void
    {
        $this->assertSame([
            9 => ['done', ['done u0009', 'waiting u0010', 'waiting u0016'], 'unreached'],
            10 => ['skipped', ['waiting u0009', 'waiting u0010'], 'unreached'],
            11 => ['done', ['done u0009', 'done u0010', 'skipped u0016'], 'unreached'],
            12 => ['skipped', ['done u0009', 'done u0010'], 'done'],
        ], array_map(fn (int $number) => $this->routeStatesOf($number), [9 => 9, 10 => 10, 11 => 11, 12 => 12]));
        $council = $this->nodeOf(9, 'council');
        $this->assertSame([1, 2], [WorkflowCaseNode::query()->where('parent_workflow_case_node_id', $council->id)->where('state', 'done')->count(), $council->decision_value]);
    }

    /**
     * 指定の記録(初期データ.md 4 節): 課長の承認の行が、合議(WF-000009・000011 = u0009・u0010・u0016、WF-000010・000012 = u0009・u0010)と責任者(u0003)を指定の順に持つ。
     */
    public function test_the_section_chief_approvals_record_the_assignments(): void
    {
        $three = ['council' => ['u0009', 'u0010', 'u0016'], 'owner' => ['u0003']];
        $two = ['council' => ['u0009', 'u0010'], 'owner' => ['u0003']];

        $this->assertSame([9 => $three, 10 => $two, 11 => $three, 12 => $two], array_map(fn (int $number) => $this->assignmentsOf($number), [9 => 9, 10 => 10, 11 => 11, 12 => 12]));
    }

    /**
     * u0002(東京営業課の課長)が版 3 で申請すると、課長(申請者の所属組織 + 課長 = u0002 だけ)が申請者本人だけのため 422 case(処理対象者.md 4.6)で、何も残らない。
     */
    public function test_u0002_applying_on_version_3_is_rejected_because_the_section_chief_is_only_the_applicant(): void
    {
        $u0002 = User::query()->where('code', 'u0002')->sole();
        $today = CarbonImmutable::today(AccessContext::TIMEZONE);
        $before = [WorkflowCase::query()->count(), Record::query()->count()];
        $input = new ApplicationInput(null, null, new RecordInput(['name' => '自分の課の計画', 'department' => $this->departmentIdOf('tokyo-sales'), 'manager' => $u0002->id, 'status' => 'planning', 'start_date' => '2026-11-01', 'budget' => 100]));

        $errors = $this->validationErrors(fn () => app(ApplyCase::class)->execute(new AccessContext($u0002->id, null, null, $today, AccessContext::LOCALE_CODE), 'project_plan', $input, $today->toDateString()));

        $this->assertSame(['case' => '課長の処理担当者が申請者本人だけのため、この操作はできません。このステップには、申請者承認防止が設定されています。'], $errors);
        $this->assertSame($before, [WorkflowCase::query()->count(), Record::query()->count()]);
    }

    /**
     * 案件の部長の状態・合議の子の「状態 処理対象者か処理者」(番号の順)・責任者の状態。
     *
     * @return array{string, list<string>, string}
     */
    private function routeStatesOf(int $number): array
    {
        $council = $this->nodeOf($number, 'council');
        $children = WorkflowCaseNode::query()->with(['workflowCaseNodeTargets.user', 'workflowCaseHistory.actor'])->where('parent_workflow_case_node_id', $council->id)->orderBy('sequence')->get();

        return [
            $this->nodeOf($number, 'manager')->state->value,
            array_values($children->map(fn (WorkflowCaseNode $child) => "{$child->state->value} ".($child->workflowCaseHistory->actor->code ?? $child->workflowCaseNodeTargets->first()->user->code ?? $this->assignedCodeOf($number, (int) $child->sequence)))->all()),
            $this->nodeOf($number, 'owner')->state->value,
        ];
    }

    /**
     * 省略された子の人(処理対象者の行を持たないので、今の指定の同じ番号の人)。
     */
    private function assignedCodeOf(int $number, int $sequence): string
    {
        return $this->assignmentsOf($number)['council'][$sequence - 1] ?? '';
    }

    /**
     * 課長の承認の行の指定(指定されたノードのコード → 人のコード。指定の順)。
     *
     * @return array<string, list<string>>
     */
    private function assignmentsOf(int $number): array
    {
        $case = WorkflowCase::query()->where('case_number', $number)->sole();
        $approval = WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->where('workflow_case_node_id', $this->nodeOf($number, 'section_chief')->id)->where('action', 'approve')->sole();
        $result = [];

        foreach (WorkflowCaseAssignment::query()->with(['user', 'workflowCaseNode'])->where('workflow_case_history_id', $approval->id)->orderBy('workflow_case_node_id')->orderBy('sequence')->get() as $row) {
            $result[(string) $row->workflowCaseNode?->code][] = (string) $row->user?->code;
        }

        return $result;
    }

    private function nodeOf(int $number, string $code): WorkflowCaseNode
    {
        return WorkflowCaseNode::query()->whereRelation('workflowCase', 'case_number', $number)->where('code', $code)->sole();
    }

    private function departmentIdOf(string $code): int
    {
        return Department::query()->where('code', $code)->sole()->id;
    }
}
