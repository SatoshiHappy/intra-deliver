<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\HoldCase;
use App\Actions\Workflow\RouteNodeInput;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use Tests\Support\OperatesHolds;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 保留(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.1 節、同時実行と検査.md の 12 節・13 節・14.2 節): 処理対象者の行と到達日時を
 * 変えず、改訂番号 +1、履歴 = 保留(node_changes = 空)、全員の未処理に残る。申請者承認防止のノードで申請者・申請者の代理先(本人として)が保留すると
 * 422 case(保留の文)。申請のノード・保留中のノードは保留できない。指定するノードも保留でき、保留中の承認は指定の入力が要る。
 * 保留と承認の同時は改訂番号の 422、開き直しても保留者側でない人は承認できない。ルート = 申請 → pair(u0002・u0003)→ final(u0010)。
 */
final class HoldCaseTest extends TransactionalTestCase
{
    use OperatesHolds;
    use RegistersProxies;

    private const NOT_PERMITTED = ['case' => 'この申請は今この操作をできません'];

    private bool $hasPairRoute = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo($this->operationDay()->setTime(12, 0));
    }

    public function test_a_hold_keeps_the_targets_and_the_reached_time_and_writes_a_hold_row(): void
    {
        $case = $this->pairCase();
        $node = $this->node($case, 'pair');
        $before = [$node->reached_at?->toIso8601String(), $this->revisionOf($case)];

        $result = $this->holdOn($case, 'pair', 'u0002');

        $node->refresh();
        $this->assertSame('hold[u0002,u0003]', $this->shape($case)['pair']);
        $after = [$node->reached_at?->toIso8601String(), $this->revisionOf($case)];
        $this->assertSame([$before[0], $before[1] + 1], $after);
        $this->assertSame(['hold', 'u0002', null, [], []], $this->latestRow($case));
        $this->assertSame([[], $result->historyId], [$result->reachedNodes, $this->latestHistoryId($case)]);
        $this->assertSame([
            'u0002' => ['pending', 'processed'],
            'u0003' => ['pending'],
        ], $this->appearances($case, 'u0002', 'u0003'));
    }

    /**
     * 防止オンのノード(東京営業課 = 申請者 u0001・u0002 と u0003)で、申請者が保留すると申請者本人の文、申請者の代理先 u0002 が本人として保留すると
     * 代理先の文。どちらも何も変わらない。u0003 は保留できる。
     */
    public function test_applicant_approval_prevention_rejects_a_hold_by_the_applicant_and_its_proxy(): void
    {
        [$applicant, $proxy] = [$this->seededUser('u0001'), $this->seededUser('u0002')];
        $this->addSalesRoute();
        $this->addProxy($applicant, $proxy);
        [$case] = $this->applyOnRoute([]);
        $snapshot = fn () => [$this->shape($case), $this->workflowCounts(), $this->revisionOf($case)];
        $before = $snapshot();

        $errors = [
            $this->attemptOf(fn () => $this->holdOn($case, 'sales', 'u0001')),
            $this->attemptOf(fn () => $this->holdOn($case, 'sales', 'u0002')),
            $this->attemptOf(fn () => $this->holdOn($case, 'sales', 'u0003')),
        ];

        $this->assertSame([
            ['case' => '申請者本人は、この申請を保留できません。このステップには、申請者承認防止が設定されています。'],
            ['case' => '申請者の代理先に設定されているため、この申請を保留できません。このステップには、申請者承認防止が設定されています。'],
            'success',
        ], $errors);
        $this->assertSame($before, $snapshot());
    }

    /**
     * 申請のノード(再申請待ち)と保留中のノードは保留できない(422 case で何も変わらない)。
     */
    public function test_an_apply_node_and_a_held_node_cannot_be_held(): void
    {
        $sentBack = $this->pairCase();
        $this->sendBackOn($sentBack, 'pair', 'u0002', 'apply');
        $held = $this->pairCase();
        $this->holdOn($held, 'pair', 'u0002');
        $snapshot = fn () => [$this->shape($sentBack), $this->shape($held), $this->workflowCounts()];
        $before = $snapshot();

        $errors = [
            $this->attemptOf(fn () => $this->holdOn($sentBack, 'apply', 'u0001')),
            $this->attemptOf(fn () => $this->holdOn($held, 'pair', 'u0002')),
            $this->attemptOf(fn () => $this->holdOn($held, 'pair', 'u0003')),
        ];

        $this->assertSame(array_fill(0, 3, self::NOT_PERMITTED), $errors);
        $this->assertSame($before, $snapshot());
    }

    /**
     * 指定するノード(R3 の chief)も保留でき(保留は指定を入力しない)、保留中の承認は指定の入力が要る。
     */
    public function test_a_designating_node_can_be_held_and_its_approval_needs_the_designation(): void
    {
        $this->addVersion($this->r3());
        [$case] = $this->applyOnRoute([]);
        $this->holdOn($case, 'chief', 'u0002');

        $missing = $this->attemptOf(fn () => $this->approve($case, 'chief', 'u0002'));
        $this->approve($case, 'chief', 'u0002', ['line' => ['u0005']]);

        $this->assertSame(['assignments' => '指定するステップの処理担当者を指定してください'], $missing);
        $after = $this->pick($case, 'chief', 'line', 'line.1');
        $this->assertSame(['chief' => 'done', 'line' => 'running', 'line.1' => 'waiting[u0005]'], $after);
    }

    /**
     * 処理対象者 u0002・u0003 の承認待ちで u0002 が保留し、u0003 が保留の前の改訂番号で承認すると改訂番号の 422。今の改訂番号でも表で不可。
     */
    public function test_a_hold_and_an_approval_at_the_same_time(): void
    {
        $case = $this->pairCase();
        $staleRevision = $this->revisionOf($case);
        $this->holdOn($case, 'pair', 'u0002');
        $approve = fn (int $revision) => $this->attemptOf(fn () => app(ApproveCase::class)->execute(
            $this->contextOf($this->seededUser('u0003')),
            $case->id,
            $revision,
            $this->node($case, 'pair')->id,
            null,
        ));

        $this->assertSame(
            [['case' => '画面を表示した後に申請が変更されました。再表示してください'], self::NOT_PERMITTED],
            [$approve($staleRevision), $approve($this->revisionOf($case))],
        );
    }

    /**
     * ノードがこの案件のノードでない・閲覧できない人は、ほかの操作と同じ判定の順(422 case・404)。
     */
    public function test_a_node_of_another_case_is_rejected(): void
    {
        $case = $this->pairCase();
        $other = $this->pairCase();
        $context = $this->contextOf($this->seededUser('u0002'));

        $error = $this->attemptOf(fn () => app(HoldCase::class)->execute(
            $context,
            $case->id,
            $this->revisionOf($case),
            $this->node($other, 'pair')->id,
            null,
        ));

        $this->assertSame(self::NOT_PERMITTED, $error);
    }

    private function pairCase(): WorkflowCase
    {
        if (! $this->hasPairRoute) {
            $pair = new RouteNodeInput(
                'pair',
                'pair',
                WorkflowNodeKind::Approve,
                WorkflowTargetKind::Fixed,
                [$this->userCondition('u0002'), $this->userCondition('u0003')],
                ['ja' => 'pair'],
                sendBackCodes: ['apply'],
            );
            $final = $this->approveNode('final', 'u0010', ['apply', 'pair']);
            $edges = $this->chain(['apply', 'pair'], ['pair', 'final']);
            $this->addVersion([[$this->applyNode(), $pair, $final], $edges]);
            $this->hasPairRoute = true;
        }

        return $this->applyOnRoute([])[0];
    }

    /**
     * 申請 → 営業(東京営業課と u0003。防止オン)→ final(u0010)の版を足す。
     */
    private function addSalesRoute(): void
    {
        $sales = new RouteNodeInput(
            'sales',
            '営業',
            WorkflowNodeKind::Approve,
            WorkflowTargetKind::Fixed,
            [
                new SelectionCondition(SelectionConditionKind::Department, $this->departmentIdOf('tokyo-sales')),
                $this->userCondition('u0003'),
            ],
            ['ja' => '営業'],
            preventsApplicantApproval: true,
            sendBackCodes: ['apply'],
        );
        $final = $this->approveNode('final', 'u0010', ['apply', 'sales']);
        $this->addVersion([[$this->applyNode(), $sales, $final], $this->chain(['apply', 'sales'], ['sales', 'final'])]);
    }

    private function node(WorkflowCase $case, string $label): WorkflowCaseNode
    {
        return WorkflowCaseNode::query()->findOrFail($this->labelIds($case)[$label]);
    }

    private function latestHistoryId(WorkflowCase $case): int
    {
        return (int) WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->max('id');
    }
}
