<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\CaseTargetTerms;
use App\Queries\Workflow\CaseVisibility;
use Tests\Support\OperatesOnRoutes;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 代理の項(docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.2 節、
 * 設計/ワークフロー/案件の運用/案件の操作/同時実行と検査.md の 14.2 節の「代理」): 代理先の未処理に代理元のノードが出る
 * (全フロー・そのフロー。別のフローは出ない)、別が合うものだけ(申請のノード ↔ 申請の代理、承認のノードと展開の行 ↔ 承認の代理)、
 * 開始日の当日は効き行の end_date の当日は効かない、代理の代理は効かない。未処理の述語と、項の 1 か所の結果(処理対象者に当たるノード)の
 * 両方で確かめる。
 */
final class ProxyTargetTermTest extends TransactionalTestCase
{
    use OperatesOnRoutes;
    use RegistersProxies;

    public function test_the_proxy_sees_the_node_of_the_principal_for_all_flows_and_that_flow_only(): void
    {
        $this->createFlow('wf_other', 'ほかのフロー');
        $case = $this->waitingCase();
        $principal = $this->seededUser('u0002');
        $this->addProxy($principal, $this->seededUser('u0005'));
        $this->addProxy($principal, $this->seededUser('u0006'), flowId: $this->flowIdOf(self::FLOW_CODE));
        $this->addProxy($principal, $this->seededUser('u0007'), flowId: $this->flowIdOf('wf_other'));

        $this->assertSame(
            ['u0002' => true, 'u0005' => true, 'u0006' => true, 'u0007' => false],
            $this->pendingFor($case, 'approval1', 'u0002', 'u0005', 'u0006', 'u0007'),
        );
    }

    public function test_the_kind_must_match_the_node(): void
    {
        $case = $this->waitingCase();
        [$approver, $applicant] = [$this->seededUser('u0002'), $this->seededUser('u0001')];
        $this->addProxy($approver, $this->seededUser('u0005'), WorkflowProxyKind::Apply);
        $this->addProxy($approver, $this->seededUser('u0006'));
        $this->addProxy($applicant, $this->seededUser('u0007'), WorkflowProxyKind::Approve);
        $this->addProxy($applicant, $this->seededUser('u0008'), WorkflowProxyKind::Apply);

        $this->assertSame(['u0005' => false, 'u0006' => true], $this->pendingFor($case, 'approval1', 'u0005', 'u0006'));

        $this->sendBack($approver, $case, 'apply');

        $this->assertSame(
            ['u0001' => true, 'u0007' => false, 'u0008' => true],
            $this->pendingFor($case, 'apply', 'u0001', 'u0007', 'u0008'),
        );
    }

    /**
     * 展開の行(横配置の子)は承認の代理で当たる(R3 = chief が line の子に u0003 を指定)。
     */
    public function test_an_expansion_row_matches_the_approve_proxy(): void
    {
        $this->addVersion($this->r3());
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'chief', 'u0002', ['line' => ['u0003']]);
        $principal = $this->seededUser('u0003');
        $this->addProxy($principal, $this->seededUser('u0005'));
        $this->addProxy($principal, $this->seededUser('u0006'), WorkflowProxyKind::Apply);

        $this->assertSame(
            ['u0003' => true, 'u0005' => true, 'u0006' => false],
            $this->pendingFor($case, 'line.1', 'u0003', 'u0005', 'u0006'),
        );
    }

    /**
     * 期間は半開区間 [start_date, end_date)(設計/ワークフロー/案件の運用/データモデル/表.md の 3.12 節)。操作の基準日 = 2026-10-13。
     */
    public function test_the_start_day_is_effective_and_the_end_date_is_not(): void
    {
        $case = $this->waitingCase();
        $principal = $this->seededUser('u0002');
        $this->addProxy($principal, $this->seededUser('u0005'), start: '2026-10-13');
        $this->addProxy($principal, $this->seededUser('u0006'), start: '2026-10-01', end: '2026-10-13');
        $this->addProxy($principal, $this->seededUser('u0007'), start: '2026-10-01', end: '2026-10-14');
        $this->addProxy($principal, $this->seededUser('u0008'), start: '2026-10-14');

        $this->assertSame(
            ['u0005' => true, 'u0006' => false, 'u0007' => true, 'u0008' => false],
            $this->pendingFor($case, 'approval1', 'u0005', 'u0006', 'u0007', 'u0008'),
        );
    }

    /**
     * D = u0002 のノードに、D の代理先 A = u0005 は当たり、A の代理先 B = u0006 は当たらない(A の本人の行が無い。項は自分から 1 段だけ)。
     */
    public function test_a_proxy_of_a_proxy_does_not_match(): void
    {
        $case = $this->waitingCase();
        [$d, $a, $b] = [$this->seededUser('u0002'), $this->seededUser('u0005'), $this->seededUser('u0006')];
        $this->addProxy($d, $a);
        $this->addProxy($a, $b);

        $this->assertSame(['u0005' => true, 'u0006' => false], $this->pendingFor($case, 'approval1', 'u0005', 'u0006'));
    }

    /**
     * 申請 u0001 → 承認 1(u0002)が承認待ちの案件(既定のフロー)。
     */
    private function waitingCase(): WorkflowCase
    {
        $this->createFlow();

        return $this->applyAs($this->seededUser('u0001'));
    }

    /**
     * 人ごとに、未処理の述語にその案件が出るか(項の 1 か所でそのノードに当たるかと一致することも確かめる)。
     *
     * @return array<string, bool>
     */
    private function pendingFor(WorkflowCase $case, string $label, string ...$codes): array
    {
        $nodeId = $this->labelIds($case)[$label];
        $result = [];

        foreach ($codes as $code) {
            $user = $this->seededUser($code);
            $date = $this->operationDay();
            $query = WorkflowCase::query()->whereKey($case->id);
            $pending = app(CaseVisibility::class)->pending($query, $this->requesterOf($user), $date)->exists();
            $targetNodeIds = app(CaseTargetTerms::class)->targetNodeIds([$nodeId], $user->id, $date);
            $this->assertSame($pending ? [$nodeId] : [], $targetNodeIds, $code);
            $result[$code] = $pending;
        }

        return $result;
    }

    private function requesterOf(User $user): Requester
    {
        return new Requester($user->id, null);
    }
}
