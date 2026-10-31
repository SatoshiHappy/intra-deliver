<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Authz\Api\CreateSelectionSubjectGroup;
use App\Actions\Workflow\CopyFlowVersion;
use App\Actions\Workflow\SetFlowVersionDeleted;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowRouteEdge;
use App\Models\Workflow\WorkflowRouteNode;
use App\Models\Workflow\WorkflowRouteSendBack;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use LogicException;
use Tests\Support\BuildsRouteVersions;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * 画面の版の追加(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md 4 節・8 節の版のテスト)。今日 T = 2026-10-13、U = T + 1 年。
 * 期待の文言は 6 節の表から書いた。失敗はどれも何も変えない。
 */
final class CopyFlowVersionTest extends TransactionalTestCase
{
    use BuildsRouteVersions;
    use CatchesValidationErrors;

    private const WITHIN_YEAR = '開始日は今日から 1 年以内の日付を入力してください';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00', AccessContext::TIMEZONE));
    }

    /**
     * 開始日が今日 − 1 日・今日・今日 + 1 年・その翌日(定義の管理.md の 1 節・6 節)と、番号と期間の連続(8 節)。
     */
    public function test_the_start_date_is_within_a_year_from_today(): void
    {
        $this->createFlow();
        $before = $this->rows();

        foreach (['2026-10-12', '2027-10-14'] as $date) {
            $this->assertSame(['start_date' => self::WITHIN_YEAR], $this->copyErrors($date), $date);
            $this->assertSame($before, $this->rows());
        }

        $this->assertSame([2, 3], [$this->copy('2026-10-13'), $this->copy('2027-10-13')]);
        $this->assertSame([
            [1, '2025-04-01', '2026-10-13', false],
            [2, '2026-10-13', '2027-10-13', false],
            [3, '2027-10-13', null, false],
        ], $this->periods());
    }

    /**
     * 最後の版の開始日以前は今日より前でもその文言が先で、その翌日は足せる(定義の管理.md の 6 節)。
     */
    public function test_a_start_date_on_or_before_the_last_start_is_rejected_first(): void
    {
        $this->addVersion($this->r3(), '2026-10-12');
        $before = $this->rows();

        foreach (['2026-10-12', '2026-10-01'] as $date) {
            $expected = ['start_date' => '開始日は版 2 の開始日 2026-10-12 より後の日付を入力してください'];
            $this->assertSame($expected, $this->copyErrors($date), $date);
            $this->assertSame($before, $this->rows());
        }

        $this->assertSame(3, $this->copy('2026-10-13'));
    }

    /**
     * 最後の版の開始日が今日 + 1 年以後なら、どの開始日も 422(定義の管理.md の 6 節)。
     */
    public function test_no_start_date_is_accepted_when_the_last_start_is_a_year_ahead(): void
    {
        $this->addVersion($this->r3(), '2027-10-13');
        $before = $this->rows();

        $expected = ['start_date' => '開始日は版 2 の開始日 2027-10-13 より後の日付を入力してください'];
        $this->assertSame($expected, $this->copyErrors('2027-10-13'));
        $this->assertSame(['start_date' => self::WITHIN_YEAR], $this->copyErrors('2027-10-14'));
        $this->assertSame($before, $this->rows());
    }

    /**
     * 閏日の今日の 1 年後は 2 月 28 日(3 月 1 日へずらさない)。
     */
    public function test_a_year_from_a_leap_day_ends_on_february_28(): void
    {
        $this->travelTo(CarbonImmutable::parse('2028-02-29 12:00', AccessContext::TIMEZONE));
        $this->createFlow();

        $this->assertSame(['start_date' => self::WITHIN_YEAR], $this->copyErrors('2029-03-01'));
        $this->assertSame(2, $this->copy('2029-02-28'));
    }

    /**
     * 複製の中身が同じ(比べる列 = id・版・参照の列・時刻を除く全部。期限・確認する人・添付の列を含む)で、版の中の参照は新しい版のノードを指す。
     */
    public function test_copies_the_nodes_edges_and_send_backs_of_the_last_version(): void
    {
        $confirmer = app(CreateSelectionSubjectGroup::class)
            ->execute([$this->userCondition('u0010')], ['ja' => '確認する人']);
        $source = $this->addVersion($this->r1($confirmer));
        WorkflowRouteNode::query()->where('workflow_flow_version_id', $source)->where('code', 'manager')
            ->update(['deadline_days' => 3, 'prevents_attachment_add' => true]);

        $copied = $this->versionId($this->copy('2026-11-01'));

        $this->assertNotSame($source, $copied);
        $this->assertSame($this->contents($source), $this->contents($copied));
        $this->assertSame([3, true, $confirmer], [
            $this->node($copied, 'manager')->deadline_days, $this->node($copied, 'manager')->prevents_attachment_add,
            $this->node($copied, 'section_chief')->confirm_authz_subject_group_id,
        ]);
    }

    /**
     * 最後の版が無効でも複製でき、新しい版は有効で始まる。
     */
    public function test_a_disabled_last_version_is_copied_into_an_enabled_version(): void
    {
        $this->createFlow();
        app(SetFlowVersionDeleted::class)->execute(self::FLOW_CODE, 1, true);

        $this->assertSame(2, $this->copy('2026-11-01'));
        $this->assertSame([[1, '2025-04-01', '2026-11-01', true], [2, '2026-11-01', null, false]], $this->periods());
    }

    /**
     * 分岐の条件の項目を業務モジュールから消した後(条件が無い項目のコードを指す)も、複製は通り、条件は写した値のまま。
     */
    public function test_copies_a_branch_condition_whose_field_was_removed_from_the_module(): void
    {
        $source = $this->addVersion($this->r1());
        $removed = ['all' => [['field' => 'removed_field', 'op' => 'ge', 'value' => 300]]];
        WorkflowRouteEdge::query()->where('workflow_flow_version_id', $source)->where('sort_key', 1)
            ->update(['condition' => json_encode($removed)]);

        $copied = $this->versionId($this->copy('2026-11-01'));

        $edge = WorkflowRouteEdge::query()->where('workflow_flow_version_id', $copied)->where('sort_key', 1)->sole();
        $this->assertEquals($removed, $edge->condition);
    }

    public function test_a_missing_flow_is_not_found_and_a_flow_without_versions_is_a_logic_error(): void
    {
        $this->assertThrows(fn () => $this->copy('2026-11-01', 'no_such_flow'), ModelNotFoundException::class);

        $flow = WorkflowFlow::factory()->createOne();
        $this->assertThrows(fn () => $this->copy('2026-11-01', $flow->code), LogicException::class);
    }

    private function copy(string $startDate, string $flowCode = self::FLOW_CODE): int
    {
        return app(CopyFlowVersion::class)->execute($flowCode, CarbonImmutable::parse($startDate));
    }

    /**
     * @return array<string, string>
     */
    private function copyErrors(string $startDate): array
    {
        return $this->validationErrors(fn () => $this->copy($startDate));
    }

    private function versionId(int $number): int
    {
        return $this->versions()->where('number', $number)->sole()->id;
    }

    private function node(int $versionId, string $code): WorkflowRouteNode
    {
        return WorkflowRouteNode::query()->where('workflow_flow_version_id', $versionId)->where('code', $code)->sole();
    }

    /**
     * @return Builder<WorkflowFlowVersion>
     */
    private function versions(): Builder
    {
        return WorkflowFlowVersion::query()->whereRelation('workflowFlow', 'code', self::FLOW_CODE);
    }

    /**
     * テストのフローの版: [番号, 開始日, 終了日, 無効か](番号の順)。
     *
     * @return list<array{int, string, string|null, bool}>
     */
    private function periods(): array
    {
        return array_values($this->versions()->orderBy('number')->get()->map(fn (WorkflowFlowVersion $version) => [
            $version->number, $version->start_date->toDateString(),
            $version->end_date?->toDateString(), $version->is_deleted,
        ])->all());
    }

    /**
     * 版の中身(列の保存値。版の中の参照は同じ版のノードのコードに置き換え、ほかの版を指せば '外')。
     *
     * @return array<string, list<mixed>>
     */
    private function contents(int $versionId): array
    {
        $nodes = WorkflowRouteNode::query()->where('workflow_flow_version_id', $versionId)->orderBy('position')->get();
        $code = fn (mixed $id) => $id === null ? null : ($nodes->firstWhere('id', $id)->code ?? '外');
        $edges = WorkflowRouteEdge::query()->where('workflow_flow_version_id', $versionId)->orderBy('id')->get();
        $sendBacks = WorkflowRouteSendBack::query()->where('workflow_flow_version_id', $versionId)->get();

        return [
            'nodes' => array_values($nodes->map(fn (WorkflowRouteNode $node) => [
                ...$this->columns($node, ['designator_workflow_route_node_id', 'closing_workflow_route_node_id']),
                'designator' => $code($node->designator_workflow_route_node_id),
                'closing' => $code($node->closing_workflow_route_node_id),
            ])->all()),
            'edges' => array_values($edges->map(fn (WorkflowRouteEdge $edge) => [
                ...$this->columns($edge, ['from_workflow_route_node_id', 'to_workflow_route_node_id']),
                'from' => $code($edge->from_workflow_route_node_id),
                'to' => $code($edge->to_workflow_route_node_id),
            ])->all()),
            'send_backs' => array_values($sendBacks->map(fn (WorkflowRouteSendBack $row) => [
                $code($row->workflow_route_node_id), $code($row->target_workflow_route_node_id),
            ])->sort()->all()),
        ];
    }

    /**
     * 行の保存値から、id・版・時刻と、渡した参照の列を除いたもの。
     *
     * @param  list<string>  $references
     * @return array<string, mixed>
     */
    private function columns(Model $row, array $references): array
    {
        $except = ['id', 'workflow_flow_version_id', 'created_at', 'updated_at', ...$references];

        return Arr::except($row->getAttributes(), $except);
    }

    /**
     * @return array<string, mixed>
     */
    private function rows(): array
    {
        return [
            'versions' => WorkflowFlowVersion::query()->orderBy('id')->get(['id', 'end_date', 'is_deleted'])->toArray(),
            'nodes' => WorkflowRouteNode::query()->count(),
            'edges' => WorkflowRouteEdge::query()->count(),
            'send_backs' => WorkflowRouteSendBack::query()->count(),
        ];
    }
}
