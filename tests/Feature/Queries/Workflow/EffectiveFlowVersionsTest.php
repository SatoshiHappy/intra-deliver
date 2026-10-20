<?php

namespace Tests\Feature\Queries\Workflow;

use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Queries\Workflow\EffectiveFlowVersions;
use Carbon\CarbonImmutable;
use Tests\Support\TransactionalTestCase;

/**
 * フローの基準日 d に有効な版を引く口(docs の 設計/ワークフロー/ルートの深さ/データモデル/定義の表.md 2.1 の「申請に使う版」): 期間が d を含み(半開区間)、
 * ユーザー無効でない版。無ければ含まない・null。版は 初期データ.md 3.1 の「プロジェクト計画の承認」と同じ並び(版 1 = 無効、版 2、版 3 = 上限なし)。
 */
final class EffectiveFlowVersionsTest extends TransactionalTestCase
{
    public function test_finds_the_version_whose_period_contains_the_date_and_that_is_not_disabled(): void
    {
        $flow = WorkflowFlow::factory()->createOne();
        WorkflowFlowVersion::factory()->createOne(['workflow_flow_id' => $flow->id, 'start_date' => '2025-04-01', 'end_date' => '2026-04-01', 'is_deleted' => true]);
        $second = WorkflowFlowVersion::factory()->createOne(['workflow_flow_id' => $flow->id, 'start_date' => '2026-04-01', 'end_date' => '2026-10-01']);
        $third = WorkflowFlowVersion::factory()->createOne(['workflow_flow_id' => $flow->id, 'start_date' => '2026-10-01']);

        $this->assertSame(
            [null, null, $second->id, $second->id, $third->id],
            array_map(fn (string $date): ?int => $this->versions()->idOn($flow->id, CarbonImmutable::parse($date)), ['2025-03-31', '2025-05-01', '2026-04-01', '2026-09-30', '2026-10-01']),
        );
    }

    public function test_maps_each_flow_to_its_version_and_leaves_out_flows_without_one(): void
    {
        $first = WorkflowFlowVersion::factory()->createOne(['start_date' => '2026-01-01']);
        $second = WorkflowFlowVersion::factory()->createOne(['start_date' => '2025-01-01']);
        $later = WorkflowFlowVersion::factory()->createOne(['start_date' => '2027-01-01']);

        $found = $this->versions()->idsOn([$first->workflow_flow_id, $second->workflow_flow_id, $later->workflow_flow_id], CarbonImmutable::parse('2026-10-13'));

        $this->assertEquals([$first->workflow_flow_id => $first->id, $second->workflow_flow_id => $second->id], $found);
        $this->assertSame([], $this->versions()->idsOn([], CarbonImmutable::parse('2026-10-13')));
    }

    private function versions(): EffectiveFlowVersions
    {
        return app(EffectiveFlowVersions::class);
    }
}
