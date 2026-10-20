<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Workflow\AddRouteVersion;
use App\Actions\Workflow\ApplyCase;
use App\Actions\Workflow\RouteEdgeInput;
use App\Actions\Workflow\RouteNodeInput;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\CaseOperationDecider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\OperatesOnRoutes;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 引戻せるノードの申請者承認防止の文言(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md の 10.5 節、
 * 設計/ワークフロー/案件の運用/案件の操作/README.md の 5 節): 申請者として扱う人の代理の表は、案件の数に依らず 1 回で引く。
 * 引戻す人 u0002 が申請者 u0001 の代理先になるのは、代理が効くフローの案件だけ。ルート = 申請 → 営業(u0002・u0003。防止オン)→ final(u0010)。
 */
final class PullBackApplicantProxyTest extends TransactionalTestCase
{
    use OperatesOnRoutes;
    use RegistersProxies;

    private const OTHER_FLOW_CODE = 'wf_other_plan';

    private const BLOCKED = '営業の処理担当者が申請者本人と申請者の代理先だけのため、この操作はできません。'
        .'このステップには、申請者承認防止が設定されています。';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo($this->operationDay()->setTime(12, 0));
        $this->addVersion($this->salesRoute());
        $this->createFlow(self::OTHER_FLOW_CODE, '別の計画の承認');
        [$nodes, $edges] = $this->salesRoute();
        $start = CarbonImmutable::parse(self::ROUTE_START);
        app(AddRouteVersion::class)->execute(self::OTHER_FLOW_CODE, $start, $nodes, $edges);
    }

    /**
     * 引戻せる防止オンのノードを持つ案件が 1 件でも 3 件でも、代理の表を読む問い合わせの数は同じ(引戻す人 u0002 は申請者 u0001 の代理先)。
     */
    public function test_the_proxy_table_is_read_the_same_number_of_times_for_one_and_three_cases(): void
    {
        $cases = array_map(fn () => $this->approvedBySales(self::FLOW_CODE), range(1, 3));
        $this->addProxy($this->seededUser('u0001'), $this->seededUser('u0002'));

        $one = $this->proxyQueriesOf([$cases[0]]);
        $three = $this->proxyQueriesOf($cases);

        $this->assertGreaterThan(0, $one);
        $this->assertSame($one, $three);
    }

    /**
     * 申請者 u0001 の代理が計画のフローだけに効くと、同じ 1 回の判定で、計画のフローの案件だけに申請者の代理先の文言が出る。
     * 期限切れの代理では出ない。
     */
    public function test_the_reason_appears_only_on_the_case_of_the_flow_the_proxy_works_on(): void
    {
        $plan = $this->approvedBySales(self::FLOW_CODE);
        $other = $this->approvedBySales(self::OTHER_FLOW_CODE);
        $planFlowId = $this->flowIdOf(self::FLOW_CODE);
        $proxy = $this->addProxy($this->seededUser('u0001'), $this->seededUser('u0002'), flowId: $planFlowId);

        $working = $this->reasonsOf([$plan, $other]);
        $proxy->update(['end_date' => $this->operationDay()->toDateString()]);
        $expired = $this->reasonsOf([$plan, $other]);

        $this->assertSame([$plan->id => self::BLOCKED, $other->id => null], $working);
        $this->assertSame([$plan->id => null, $other->id => null], $expired);
    }

    /**
     * u0002 が営業を承認した案件(u0002 は営業を引戻せる)。
     */
    private function approvedBySales(string $flowCode): WorkflowCase
    {
        $input = $this->projectInput();
        $context = $this->contextOf($this->seededUser('u0001'));
        $result = app(ApplyCase::class)->execute($context, $flowCode, $input, $this->operationDay()->toDateString());
        $case = WorkflowCase::query()->findOrFail($result->caseId);
        $this->approve($case, 'sales', 'u0002');

        return $case;
    }

    /**
     * u0002 の状況を 1 回で作り、案件の id → 営業の blockedReason を返す。
     *
     * @param  list<WorkflowCase>  $cases
     * @return array<int, string|null>
     */
    private function reasonsOf(array $cases): array
    {
        $context = $this->contextOf($this->seededUser('u0002'));
        $requester = Requester::fromAccessContext($context);
        $situations = app(CaseOperationDecider::class)->situationsOf($requester, $context->date, $cases);
        $reasons = [];

        foreach ($cases as $case) {
            $nodes = $situations[$case->id]->pullBackNodes;
            $this->assertCount(1, $nodes, "案件 {$case->id} の引戻せるノードが営業だけでない");
            $reasons[$case->id] = array_values($nodes)[0]['blockedReason'];
        }

        return $reasons;
    }

    /**
     * u0002 の状況を作る間に、代理の表を読んだ問い合わせの数。
     *
     * @param  list<WorkflowCase>  $cases
     */
    private function proxyQueriesOf(array $cases): int
    {
        // 呼ぶごとに別の数に数える(前の呼び出しの聞き手は、その呼び出しの数だけを増やす)
        $count = 0;
        DB::listen(function (QueryExecuted $query) use (&$count): void {
            if (str_contains($query->sql, '"workflow_proxies"')) {
                $count++;
            }
        });

        $this->reasonsOf($cases);

        return $count;
    }

    /**
     * @return array{list<RouteNodeInput>, list<RouteEdgeInput>}
     */
    private function salesRoute(): array
    {
        $sales = new RouteNodeInput(
            'sales',
            '営業',
            WorkflowNodeKind::Approve,
            WorkflowTargetKind::Fixed,
            [$this->userCondition('u0002'), $this->userCondition('u0003')],
            ['ja' => '営業'],
            preventsApplicantApproval: true,
            sendBackCodes: ['apply'],
        );
        $final = $this->approveNode('final', 'u0010', ['apply', 'sales']);

        return [[$this->applyNode(), $sales, $final], $this->chain(['apply', 'sales'], ['sales', 'final'])];
    }
}
