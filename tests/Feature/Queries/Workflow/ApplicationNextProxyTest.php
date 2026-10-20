<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Workflow\ReapplyCase;
use App\Actions\Workflow\RouteEdgeInput;
use App\Actions\Workflow\RouteNodeInput;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Enums\Workflow\WorkflowRelativeBase;
use App\Enums\Workflow\WorkflowRelativeScope;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNodeTarget;
use App\Queries\Workflow\ApplicationNext;
use App\Support\AccessContext;
use Tests\Support\OperatesOnRoutes;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 再申請の画面の先読み(docs の 設計/ワークフロー/ルートの深さ/画面.md 5 節の next)の前処理者は申請者
 * (設計/ワークフロー/案件の運用/案件の操作/README.md の 3.2 節・4.5 節)。申請の代理先が開いても、先読みは再申請の計画と同じ人(申請者を起点にした展開)で、代理先の選んだ組織の人にならない。
 * 申請者 A = u0001(東京営業課)、A の申請の代理先 B = u0009(総務部が主所属、人事部の兼務。人事部を選ぶ)。
 * ルート = 申請 → p(前処理者の組織の人)→ final(u0010)。
 */
final class ApplicationNextProxyTest extends TransactionalTestCase
{
    use OperatesOnRoutes;
    use RegistersProxies;

    public function test_the_preview_of_a_reapply_by_the_proxy_is_the_expansion_from_the_applicant(): void
    {
        $this->addVersion($this->previousRoute());
        [$case] = $this->applyOnRoute([]);
        $this->sendBackOn($case, 'p', $this->firstTargetOf($case, 'p'), 'apply');
        [$a, $b] = [$this->seededUser('u0001'), $this->seededUser('u0009')];
        $this->addProxy($a, $b, WorkflowProxyKind::Apply);
        $byProxy = $this->contextOf($b, $this->departmentIdOf('human-resources'));

        $previews = [$this->previewOf($byProxy, $case), $this->previewOf($this->contextOf($a), $case)];
        $input = $this->projectInput();
        app(ReapplyCase::class)->execute($byProxy, $case->id, $this->revisionOf($case), $input, $a->id);
        $reached = $this->targetCodesOf($case, 'p');

        $this->assertNotSame([], $reached);
        $this->assertNotContains('u0009', $reached);
        $this->assertSame([$reached, $reached], $previews);
    }

    /**
     * 先読みの最初のノードの処理対象者のコード。
     *
     * @return list<string>
     */
    private function previewOf(AccessContext $context, WorkflowCase $case): array
    {
        $next = app(ApplicationNext::class)->ofCase($context, $case->refresh());

        $codes = array_column($next['nodes'][0]['targets'], 'code');
        sort($codes);

        return $codes;
    }

    /**
     * @return list<string>
     */
    private function targetCodesOf(WorkflowCase $case, string $label): array
    {
        $nodeId = $this->labelIds($case)[$label];
        $rows = WorkflowCaseNodeTarget::query()->with('user')->where('workflow_case_node_id', $nodeId)->get();
        $codes = array_values(array_map(fn (WorkflowCaseNodeTarget $row) => (string) $row->user?->code, $rows->all()));
        sort($codes);

        return $codes;
    }

    private function firstTargetOf(WorkflowCase $case, string $label): string
    {
        return $this->targetCodesOf($case, $label)[0] ?? $this->fail("{$label} に処理対象者がいない");
    }

    /**
     * @return array{list<RouteNodeInput>, list<RouteEdgeInput>}
     */
    private function previousRoute(): array
    {
        $previous = new RouteNodeInput(
            'p',
            'p',
            WorkflowNodeKind::Approve,
            WorkflowTargetKind::Relative,
            relativeBase: WorkflowRelativeBase::Previous,
            relativeScope: WorkflowRelativeScope::Own,
            sendBackCodes: ['apply'],
        );

        $final = $this->approveNode('final', 'u0010', ['apply', 'p']);

        return [[$this->applyNode(), $previous, $final], $this->chain(['apply', 'p'], ['p', 'final'])];
    }
}
