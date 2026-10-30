<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Workflow\RouteEdgeInput;
use App\Actions\Workflow\RouteNodeInput;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Workflow\ActiveApplicationsHomeSection;
use App\Queries\Workflow\PendingCasesHomeSection;
use App\Queries\Workflow\WorkflowCountersHomeSection;
use Tests\Support\OperatesOnRoutes;
use Tests\Support\TransactionalTestCase;

/**
 * 現在のノードが複数になるルートでのホームの節(docs の 設計/ワークフロー/ルートの深さ/認可と一覧.md 2 節のユーザーの決定 H1〜H3、7 節の「ホームの節」)。
 * 期待値は 2 節の規則(件数と未処理の表の状態は案件ごとに一覧の行の札の規則、経路は写したノード(展開の行を除く)を状態どおり、省略は skipped)から書いた。
 * 案件は本物の業務処理で作る({@see OperatesOnRoutes}。申請者 u0001。初期データの案件の見本もある)。
 */
final class WorkflowHomeOnRoutesTest extends TransactionalTestCase
{
    use OperatesOnRoutes;

    /**
     * 並列の 2 つのノードの処理対象者でも、未処理の件数は案件の数で 1・内訳「承認待ち 1」、未処理の表の状態は「承認待ち」(H1)。
     */
    public function test_a_person_targeted_by_two_parallel_nodes_counts_the_case_once(): void
    {
        $this->addVersion($this->bothBranchesTo('u0004'));
        [$case] = $this->applyOnRoute([]);

        $this->assertSame(['a1' => 'waiting[u0004]', 'b1' => 'waiting[u0004]'], $this->pick($case, 'a1', 'b1'));
        $counters = app(WorkflowCountersHomeSection::class)->section($this->contextOf($this->seededUser('u0004')));
        $this->assertSame([1, '承認待ち 1'], [$counters['items'][0]['count'] ?? null, $counters['items'][0]['note'] ?? null]);
        $pending = app(PendingCasesHomeSection::class)->section($this->contextOf($this->seededUser('u0004')));
        $this->assertSame([[(string) $case->id, '承認待ち', 'pending']], array_map(fn (array $row) => [$row['code'], $row['cells'][3]['text'], $row['cells'][3]['tone']], $pending['rows'] ?? []));
    }

    /**
     * 合議の進行中の申請: note は「<案件の札の語>：<現在のノードの表示名を「、」で並べる>」、経路は分岐・同期のノードも点にし、配置は親の 1 点で状態どおり(H2・H3)。語は点と 1 対 1(通過 = 処理済み、展開中 = 承認待ち = S1)。
     */
    public function test_the_route_of_a_council_in_progress_lists_the_children_in_the_note(): void
    {
        $this->addVersion($this->r1());
        [$case] = $this->applyOnRoute(['budget' => 500]);
        $this->approve($case, 'section_chief', 'u0002', ['council' => ['u0004', 'u0005', 'u0009'], 'owner' => ['u0010']]);
        $this->approve($case, 'manager', 'u0003');

        $this->assertSame([
            '承認待ち：関係部署の合議(承認 1、承認 2、承認 3)',
            [['申請', 'done', '処理済み'], ['課長', 'done', '処理済み'], ['予算', 'done', '処理済み'], ['部長', 'done', '処理済み'], ['分岐終了', 'done', '処理済み'], ['関係部署の合議', 'current', '承認待ち'], ['責任者', 'upcoming', '到達していない']],
        ], $this->routeOf($case));
    }

    /**
     * 分岐で通らなかった枝は skipped(「省略」)。済んだ枝のノードは、位置が現在のノードより後ろでも done(段 2 の位置の規則を外した = H2)。
     */
    public function test_skipped_branches_and_done_branches_are_drawn_as_they_are(): void
    {
        $this->addVersion($this->r1());
        [$skipped] = $this->applyOnRoute(['budget' => 100]);
        $this->approve($skipped, 'section_chief', 'u0002', ['council' => ['u0004', 'u0005'], 'owner' => ['u0010']]);

        $this->assertSame(['部長', 'skipped', '省略'], $this->routeOf($skipped)[1][3]);

        $this->addVersion($this->r2(), '2026-06-01');
        [$parallel] = $this->applyOnRoute([]);
        $this->approve($parallel, 'b1', 'u0004');

        $this->assertSame([
            '承認待ち：a1',
            [['申請', 'done', '処理済み'], ['同期', 'done', '処理済み'], ['a1', 'current', '承認待ち'], ['a2', 'upcoming', '到達していない'], ['b1', 'done', '処理済み'], ['同期終了', 'upcoming', '到達していない'], ['final', 'upcoming', '到達していない']],
        ], $this->routeOf($parallel));
    }

    /**
     * 差戻しの後の経路は段 2 の Q5 の期待のまま(差戻し先が再申請待ち、ほかは到達していない。差戻し先と子孫を到達していないに戻すため)。
     */
    public function test_the_route_after_a_send_back_keeps_the_stage_2_picture(): void
    {
        $this->addVersion($this->r1());
        [$case] = $this->applyOnRoute(['budget' => 500]);
        $this->approve($case, 'section_chief', 'u0002', ['council' => ['u0004', 'u0005'], 'owner' => ['u0010']]);
        $this->sendBackOn($case, 'manager', 'u0003', 'apply');

        $this->assertSame([
            '再申請待ち：申請',
            [['申請', 'returned', '再申請待ち'], ['課長', 'upcoming', '到達していない'], ['予算', 'upcoming', '到達していない'], ['部長', 'upcoming', '到達していない'], ['分岐終了', 'upcoming', '到達していない'], ['関係部署の合議', 'upcoming', '到達していない'], ['責任者', 'upcoming', '到達していない']],
        ], $this->routeOf($case));
    }

    /**
     * 申請者 u0001 の進行中の申請の経路のうち、その案件の項目の note とステップ。
     *
     * @return array{string, list<array{string, string, string}>}
     */
    private function routeOf(WorkflowCase $case): array
    {
        $section = app(ActiveApplicationsHomeSection::class)->section($this->contextOf($this->seededUser('u0001')));
        $items = array_values(array_filter($section['items'] ?? [], fn (array $item) => $item['code'] === (string) $case->id));
        $this->assertCount(1, $items, "案件 {$case->id} が進行中の申請の先頭 3 件に無い");

        return [$items[0]['note'], array_map(fn (array $step) => [$step['label'], $step['state'], $step['stateLabel']], $items[0]['steps'])];
    }

    /**
     * R2 の両方の枝の最初の承認(a1・b1)を同じ人にした形。
     *
     * @return array{list<RouteNodeInput>, list<RouteEdgeInput>}
     */
    private function bothBranchesTo(string $userCode): array
    {
        [$nodes, $edges] = $this->r2();
        $replaced = ['a1' => $this->approveNode('a1', $userCode, ['apply']), 'b1' => $this->approveNode('b1', $userCode, ['apply'])];

        return [array_map(fn (RouteNodeInput $node) => $replaced[$node->code] ?? $node, $nodes), $edges];
    }
}
