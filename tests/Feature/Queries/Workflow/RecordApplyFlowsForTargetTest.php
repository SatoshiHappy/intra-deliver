<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\ApplicableFlows;
use App\Queries\Workflow\RecordApplyFlow;
use App\Queries\Workflow\RecordApplyFlows;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsTargetFlows;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 申請対象からの申請の口(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の 9.1・11 節): 申請のノードの対象者 +
 * 申請書の業務モジュールの create + 元の業務モジュールの read(update は求めない)。申請対象の無いコンテンツのフローは誰も申請できない。
 * flowsForTarget・allowsAnyForTarget は自分か代理元のどれか、allowsForTarget はその人だけ。業務モジュールとフローはテストで作る。
 */
final class RecordApplyFlowsForTargetTest extends TransactionalTestCase
{
    use BuildsTargetFlows;
    use BuildsWorkflowCases;
    use RegistersProxies;

    private const DATE = '2026-10-13';

    /**
     * 欠けた条件ごとに申請できない(対象者でない・申請書の create が無い・元の read が無い・申請対象が無い)。update が無くても申請できる。
     */
    public function test_needs_the_apply_node_the_form_create_and_the_target_read_but_not_update(): void
    {
        [$u0001, $u0002] = $this->seededUsers('u0001', 'u0002');
        $create = [RecordModuleAction::Create];
        $read = [RecordModuleAction::Read];
        $readUpdate = [RecordModuleAction::Read, RecordModuleAction::Update];
        $updateCreate = [RecordModuleAction::Update, RecordModuleAction::Create];
        $flows = [
            'ok' => $this->flowTo('ok', $read, $create, [$u0001]),
            'no_node' => $this->flowTo('no_node', $read, $create, [$u0002]),
            'no_create' => $this->flowTo('no_create', $read, $readUpdate, [$u0001]),
            'no_read' => $this->flowTo('no_read', $updateCreate, $create, [$u0001]),
            'no_update' => $this->flowTo(
                'no_update', [RecordModuleAction::Read, RecordModuleAction::Create],
                [RecordModuleAction::Create, RecordModuleAction::Read], [$u0001],
            ),
            'no_target' => $this->targetFlow('no_target', null, RecordModuleAction::cases(), [$u0001]),
        ];
        $requester = $this->requester($u0001);
        $judge = fn (WorkflowFlow $flow) => [
            $this->flows()->allowsForTarget($requester, $flow->id, self::day()),
            $this->flows()->allowsAnyForTarget($requester, $flow->id, self::day()),
            $this->flows()->applicantIdsForTarget($requester, $flow->id, self::day()),
            $this->codesFor($requester, "m_t_{$flow->code}"),
        ];

        $this->assertSame([
            'ok' => [true, true, [$u0001->id], ['ok']],
            'no_node' => [false, false, [], []],
            'no_create' => [false, false, [], []],
            'no_read' => [false, false, [], []],
            'no_update' => [true, true, [$u0001->id], ['no_update']],
            'no_target' => [false, false, [], []],
        ], array_map($judge, $flows));
        $this->assertFalse($this->flows()->allows($requester, $flows['ok']->id, self::day()));
    }

    /**
     * 代理(自分は元の read を持たず、代理元が持つ): allowsAnyForTarget・flowsForTarget は通り、allowsForTarget は代理元で通り自分では通らない。
     * 候補は代理元だけ。承認の代理は効かない。
     */
    public function test_a_principal_counts_for_any_but_not_for_the_proxy_alone(): void
    {
        [$principal, $proxy, $other] = $this->seededUsers('u0009', 'u0002', 'u0003');
        $subjects = [$principal, $proxy, $other];
        $flow = $this->flowTo('by_principal', [RecordModuleAction::Read], [RecordModuleAction::Create], $subjects);
        $this->allowOnly('m_t_by_principal', RecordModuleAction::Read, $principal);
        $this->addProxy($principal, $proxy, WorkflowProxyKind::Apply, $flow->id);
        $this->addProxy($principal, $other, WorkflowProxyKind::Approve, $flow->id);
        $day = self::day();

        $this->assertSame([true, false, true, [$principal->id], ['by_principal']], [
            $this->flows()->allowsAnyForTarget($this->requester($proxy), $flow->id, $day),
            $this->flows()->allowsForTarget($this->requester($proxy), $flow->id, $day),
            $this->flows()->allowsForTarget($this->requester($principal), $flow->id, $day),
            $this->flows()->applicantIdsForTarget($this->requester($proxy), $flow->id, $day),
            $this->codesFor($this->requester($proxy), 'm_t_by_principal'),
        ]);
        $this->assertSame([false, [], []], [
            $this->flows()->allowsAnyForTarget($this->requester($other), $flow->id, $day),
            $this->flows()->applicantIdsForTarget($this->requester($other), $flow->id, $day),
            $this->codesFor($this->requester($other), 'm_t_by_principal'),
        ]);
    }

    /**
     * 選べるフローは、今日の版が有効で、申請対象の参照先がその業務モジュールのものだけ。並びはフローの一覧と同じ(sort_key・code)。ゲストは空。
     */
    public function test_flows_for_target_follow_the_flow_list_and_filter_by_the_target_and_the_version(): void
    {
        [$u0001] = $this->seededUsers('u0001');
        $shared = $this->recordModule('m_shared_target', [RecordModuleAction::Read]);
        $create = [RecordModuleAction::Create];
        $this->targetFlow('b_flow', $shared, $create, [$u0001], sortKey: 2);
        $this->targetFlow('z_flow', $shared, $create, [$u0001], sortKey: 1);
        $this->targetFlow('a_flow', $shared, $create, [$u0001], sortKey: 1);
        $future = $this->targetFlow('future', $shared, $create, [$u0001]);
        WorkflowFlowVersion::query()->where('workflow_flow_id', $future->id)->update(['start_date' => '2026-10-14']);
        $this->flowTo('other_target', [RecordModuleAction::Read], $create, [$u0001]);

        $codes = $this->codesFor($this->requester($u0001), 'm_shared_target');
        $context = new AccessContext($u0001->id, null, null, self::day(), 'ja');
        $flowList = array_values(array_filter(
            array_column(app(ApplicableFlows::class)->flows($context), 'code'),
            fn (string $code) => in_array($code, ['a_flow', 'b_flow', 'z_flow', 'future'], true),
        ));

        $this->assertSame(['a_flow', 'z_flow', 'b_flow'], $codes);
        $this->assertSame($flowList, $codes);
        $this->assertSame([], $this->codesFor($this->requester(null), 'm_shared_target'));
        $this->assertSame([], $this->codesFor($this->requester($u0001), 'm_missing'));
    }

    /**
     * 申請対象の参照先 m_t_<コード>(作るときに全員へ $targetPermitted を許す)を指すフロー。
     *
     * @param  list<RecordModuleAction>  $targetPermitted
     * @param  list<RecordModuleAction>  $formPermitted
     * @param  list<User>  $applicants
     */
    private function flowTo(string $code, array $targetPermitted, array $formPermitted, array $applicants): WorkflowFlow
    {
        $target = $this->recordModule("m_t_{$code}", $targetPermitted);

        return $this->targetFlow($code, $target, $formPermitted, $applicants);
    }

    private function flows(): RecordApplyFlows
    {
        return app(RecordApplyFlows::class);
    }

    /**
     * @return list<string> flowsForTarget のフローのコード(並びのまま)
     */
    private function codesFor(Requester $requester, string $targetModuleCode): array
    {
        $flows = $this->flows()->flowsForTarget($requester, $targetModuleCode, self::day());

        return array_map(fn (RecordApplyFlow $flow) => $flow->code, $flows);
    }

    private static function day(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::DATE);
    }

    private function requester(?User $user): Requester
    {
        return new Requester($user?->id, null);
    }
}
