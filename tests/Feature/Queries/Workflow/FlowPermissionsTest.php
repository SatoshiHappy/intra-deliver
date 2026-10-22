<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Workflow\WorkflowFlowAction;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\FlowPermissions;
use Tests\Support\GrantsFlowPermissions;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * workflow-flow の判定の 1 か所(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 3 節・6 節): 初期データのポリシーでシステム管理者(u0004)は
 * 全フローで read・operate を許され、一般の利用者(u0001)はどちらも許されない。テストの中で read だけ・operate だけを足した利用者はそのとおり。
 * フローは初期データの 2 つと、テストの支えが作る 2 つ(どれもフローのリソースを持つ)。
 */
final class FlowPermissionsTest extends TransactionalTestCase
{
    use GrantsFlowPermissions;
    use RunsWorkflowOperations;

    private int $plan;

    private int $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plan = $this->createFlow();
        $this->other = $this->createFlow('wf_other', '別のフロー');
    }

    public function test_the_system_administrator_may_read_and_operate_every_flow_and_a_general_user_neither(): void
    {
        $all = array_values(array_map('intval', WorkflowFlow::query()->orderBy('id')->pluck('id')->all()));

        $this->assertCount(4, $all);
        $this->assertSame($all, $this->readable('u0004'));
        $this->assertSame([true, true, true, true], $this->operable('u0004', $all));
        $this->assertSame([], $this->readable('u0001'));
        $this->assertSame([false, false, false, false], $this->operable('u0001', $all));
    }

    public function test_a_user_granted_only_read_or_only_operate_gets_exactly_that(): void
    {
        $this->grantFlow($this->seededUser('u0006'), 'wf_plan', WorkflowFlowAction::Read);
        $this->grantFlow($this->seededUser('u0007'), 'wf_other', WorkflowFlowAction::Operate);

        $this->assertSame([$this->plan], $this->readable('u0006'));
        $this->assertSame([false, false], $this->operable('u0006', [$this->plan, $this->other]));
        $this->assertSame([], $this->readable('u0007'));
        $this->assertSame([false, true], $this->operable('u0007', [$this->plan, $this->other]));
    }

    /**
     * リソースを持たないフローと、存在しないフローは、システム管理者にも許されない(URI のリソースが無ければ禁止)。
     */
    public function test_a_flow_without_its_resource_and_a_missing_flow_are_never_permitted(): void
    {
        $bare = WorkflowFlow::factory()->createOne();
        $missing = (int) WorkflowFlow::query()->max('id') + 1000;

        $this->assertNotContains($bare->id, $this->readable('u0004'));
        $this->assertSame([false, false], $this->operable('u0004', [$bare->id, $missing]));
    }

    /**
     * @return list<int>
     */
    private function readable(string $userCode): array
    {
        return $this->permissions()->readableFlowIds($this->requester($userCode), $this->operationDay());
    }

    /**
     * @param  list<int>  $flowIds
     * @return list<bool>
     */
    private function operable(string $userCode, array $flowIds): array
    {
        $requester = $this->requester($userCode);
        $day = $this->operationDay();

        return array_map(fn (int $flowId) => $this->permissions()->canOperate($requester, $day, $flowId), $flowIds);
    }

    private function requester(string $userCode): Requester
    {
        return new Requester($this->seededUser($userCode)->id, null);
    }

    private function permissions(): FlowPermissions
    {
        return app(FlowPermissions::class);
    }
}
