<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Workflow\RouteNodeInput;
use App\Enums\Workflow\WorkflowFlowAction;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Workflow\CaseDetailQuery;
use App\Queries\Workflow\RetargetUserLimit;
use Tests\Support\GrantsCaseOperationRoutes;
use Tests\Support\GrantsFlowPermissions;
use Tests\Support\OperatesAsCaseManager;
use Tests\Support\TransactionalTestCase;

/**
 * 詳細の申請操作の面(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.2 節、認可と一覧.md の 3 節、案件の操作/案件操作.md の 15.2 節):
 * ボタン = operate ∧ ルートの execute(保留解除は operate だけ)∧ 表、can.operate = どれかのボタン、偽なら operation = null。ダイアログの中身。
 * R1 = 申請 → 課長(u0002。合議と責任者を指定する)→ 合議(縦配置)→ 責任者(動的承認)。申請者 u0001、管理者 u0004、今日 = 2026-10-13。
 * u0002 は課長として処理した人(処理済の述語で閲覧できる)で、テストの中でルートの execute・operate を足して 2 段を確かめる。
 */
final class CaseOperationPanelTest extends TransactionalTestCase
{
    use GrantsCaseOperationRoutes;
    use GrantsFlowPermissions;
    use OperatesAsCaseManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo($this->operationDay()->setTime(12, 0));
        $this->addVersion($this->r1());
    }

    /**
     * 現在のノードが 2 つ(合議の子)の未完了の案件: 処理担当者の変更・再展開・移動の選択肢が 2 つ(展開の行は 1 人の印)、再展開の展開後 = 今の指定の
     * その番号の人、戻り先の候補 = 申請と課長(既定 = 最後の課長)、指定を変更できるステップ = 到達していない責任者だけ(到達した合議は出ない)。
     * 保留中のノードが無いので保留解除は偽。
     */
    public function test_the_buttons_and_the_dialogs_of_two_current_nodes(): void
    {
        $case = $this->councilCase();
        $labels = ['council.1', 'council.2', 'apply', 'section_chief', 'owner'];
        [$first, $second, $apply, $chief, $owner] = $this->idsAt($case, ...$labels);

        $operation = $this->operationOf(self::MANAGER, $case);

        $this->assertSame($this->buttons(release: false), $operation['buttons']);
        $this->assertSame([$case->name, []], [$operation['caseName'], $operation['releaseNodes']]);
        $retarget = fn (array $node) => [
            $node['nodeId'], $node['single'], $node['max'], $node['onHold'], $this->codes($node['targets']),
        ];
        $this->assertSame(
            [[$first, true, 1, false, ['u0005']], [$second, true, 1, false, ['u0009']]],
            array_map($retarget, $operation['retargetNodes']),
        );
        $reexpand = fn (array $node) => [
            $node['nodeId'], $this->codes($node['targets']), $this->codes($node['expanded']), $node['summary'],
        ];
        $this->assertSame(
            [[$first, ['u0005'], ['u0005'], null], [$second, ['u0009'], ['u0009'], null]],
            array_map($reexpand, $operation['reexpandNodes']),
        );
        $back = [['nodeId' => $apply, 'name' => '申請'], ['nodeId' => $chief, 'name' => '課長']];
        $move = fn (array $node) => [
            $node['nodeId'], $node['canForward'], $node['backNodes'], $node['defaultBackNodeId'],
        ];
        $moves = array_map($move, $operation['moveNodes']);
        $this->assertSame([[$first, true, $back, $chief], [$second, true, $back, $chief]], $moves);
        $this->assertSame([[$owner, '責任者', '承認', 1, 1, false, ['u0010']]], $this->assignRows($operation));
    }

    /**
     * 完了の案件は申請の削除だけ(ほかのボタンは偽、ダイアログの中身は空)。
     */
    public function test_a_completed_case_offers_only_the_deletion(): void
    {
        $case = $this->councilCase();
        $this->moveOn($case, 'council.1', 'finish');

        $operation = $this->operationOf(self::MANAGER, $case);

        $none = ['retarget' => false, 'reexpand' => false, 'move' => false, 'assign' => false, 'release' => false];
        $this->assertSame([...$none, 'destroy' => true], $operation['buttons']);
        $lists = ['retargetNodes' => [], 'reexpandNodes' => [], 'moveNodes' => [], 'assignNodes' => []];
        $this->assertSame($lists, array_intersect_key($operation, $lists));
    }

    /**
     * 認可の 2 段(operate × ルートの execute)を、案件を閲覧できる利用者ごとに足して展開する(テストの中でポリシーを足す): 両方なし(u0002)・
     * execute だけ(u0005)= 面なし(operation = null、can.operate = 偽)、operate だけ(u0009)= 保留中の案件では保留解除だけ・保留中でなければ面なし、
     * 両方(u0001)= 管理者と同じボタン。
     */
    public function test_the_two_steps_of_the_authorization(): void
    {
        $active = $this->councilCase();
        $held = $this->councilCase();
        $this->holdOn($held, 'council.1', 'u0005');
        [$routeOnly, $operateOnly, $both] = array_map($this->seededUser(...), ['u0005', 'u0009', 'u0001']);
        $this->grantCaseOperationRoutes($routeOnly);
        $this->grantFlow($operateOnly, self::FLOW_CODE, WorkflowFlowAction::Operate);
        $this->grantCaseOperationRoutes($both);
        $this->grantFlow($both, self::FLOW_CODE, WorkflowFlowAction::Operate);

        $none = ['retarget' => false, 'reexpand' => false, 'move' => false, 'assign' => false];
        $this->assertSame([false, null], $this->panelOf('u0002', $held));
        $this->assertSame([false, null], $this->panelOf('u0005', $held));
        $this->assertSame([false, null], $this->panelOf('u0009', $active));
        $releaseOnly = [...$none, 'release' => true, 'destroy' => false];
        $releasing = $this->operationOf('u0009', $held);
        $this->assertSame($releaseOnly, $releasing['buttons']);
        $this->assertSame($this->idsAt($held, 'council.1'), array_column($releasing['releaseNodes'], 'nodeId'));
        $this->assertSame($this->buttons(release: false), $this->operationOf('u0001', $active)['buttons']);
        $this->assertSame($this->buttons(release: true), $this->operationOf('u0001', $held)['buttons']);
    }

    /**
     * 保留中のノード(合議の子 1 を u0005 が保留。管理者は保留者側でない): 保留解除・処理担当者の変更・再展開・移動が真で、そのノードの印が保留中。
     * 保留解除で開くステップ = そのノード(表示名は処理担当者の変更と同じ)。
     */
    public function test_a_held_node(): void
    {
        $case = $this->councilCase();
        $this->holdOn($case, 'council.1', 'u0005');

        $operation = $this->operationOf(self::MANAGER, $case);

        $this->assertSame($this->buttons(release: true), $operation['buttons']);
        $held = fn (string $key) => array_column($operation[$key], 'onHold');
        $marks = [$held('retargetNodes'), $held('reexpandNodes'), $held('moveNodes')];
        $this->assertSame([[true, false], [true, false], [true, false]], $marks);
        $first = $operation['retargetNodes'][0];
        $this->assertSame([['nodeId' => $first['nodeId'], 'name' => $first['name']]], $operation['releaseNodes']);
    }

    /**
     * 課長(指定するノード)が現在のノード: 進めない(進むの業務処理と同じ式)、戻り先の候補は申請だけ。合議と責任者の指定するノードがまだ処理済みでないので
     * 指定の変更は偽。再展開の展開後が 0 人なら空(写した役職を課長の組織にいない部長に替えて作る)でも、ボタンは真(決定を押せないのは画面)。
     * 展開の行でないので、処理担当者の変更の上限は 20 人(展開の行の 1 人は合議の案件の検査)。
     */
    public function test_a_designating_node_and_an_expansion_to_nobody(): void
    {
        [$case] = $this->applyOnRoute([]);
        [$apply, $chief] = $this->idsAt($case, 'apply', 'section_chief');
        WorkflowCaseNode::query()->whereKey($chief)->update(['company_post_id' => $this->postId('manager')]);

        $operation = $this->operationOf(self::MANAGER, $case);

        $buttons = $operation['buttons'];
        $this->assertSame([false, true, true], [$buttons['assign'], $buttons['reexpand'], $buttons['move']]);
        $this->assertSame([], $operation['assignNodes']);
        $retarget = fn (array $node) => [$node['nodeId'], $node['single'], $node['max']];
        $retargetValues = array_map($retarget, $operation['retargetNodes']);
        $this->assertSame([[$chief, false, RetargetUserLimit::MAX_USERS]], $retargetValues);
        $reexpand = fn (array $node) => [$node['nodeId'], $this->codes($node['targets']), $node['expanded']];
        $this->assertSame([[$chief, ['u0002'], []]], array_map($reexpand, $operation['reexpandNodes']));
        $move = $operation['moveNodes'][0];
        $moveValues = [$move['canForward'], $move['backNodes'], $move['defaultBackNodeId']];
        $this->assertSame([false, [['nodeId' => $apply, 'name' => '申請']], $apply], $moveValues);
    }

    /**
     * 指定を変更できるステップの前提の境界: 到達していない横配置は出る(並びが処理の順・最小と最大・今の指定の並び)。ほかのノードの指定するノード y と、
     * 指定するノードが処理済みでない z は出ない(どちらも到達していない)。
     */
    public function test_the_reassignable_nodes(): void
    {
        $nodes = [
            $this->applyNode(),
            $this->approveNode('chief', 'u0002', ['apply']),
            $this->approveNode('a2', 'u0003', ['apply']),
            $this->designated('line', '順番の承認', WorkflowNodeKind::Serial, 'chief', 1, 3),
            $this->designated('y', 'y', WorkflowNodeKind::Approve, 'chief', 1, 1),
            $this->designated('z', 'z', WorkflowNodeKind::Approve, 'y', 0, 1),
        ];
        $edges = $this->chain(['apply', 'chief'], ['chief', 'a2'], ['a2', 'line'], ['line', 'y'], ['y', 'z']);
        $this->addVersion([$nodes, $edges], '2026-02-01');
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'chief', 'u0002', ['line' => ['u0009', 'u0005'], 'y' => ['u0010']]);

        $operation = $this->operationOf(self::MANAGER, $case);

        $line = $this->idsAt($case, 'line')[0];
        $this->assertSame([[$line, '順番の承認', '横配置', 1, 3, true, ['u0009', 'u0005']]], $this->assignRows($operation));
    }

    /**
     * ボタンが真の組は、面の値で送った業務処理が表の拒否(422 case)を返さない(各ボタン 1 回。人は規則に合う人を選ぶ)。
     */
    public function test_each_true_button_passes_the_operation(): void
    {
        $operationOf = fn (WorkflowCase $case) => $this->operationOf(self::MANAGER, $case);
        $label = fn (WorkflowCase $case, int $nodeId) => (string) array_search($nodeId, $this->labelIds($case), true);
        $first = fn (WorkflowCase $case, string $key, int $index = 0) => $label(
            $case,
            $operationOf($case)[$key][$index]['nodeId'],
        );
        $cases = array_map(fn () => $this->councilCase(), range(1, 6));
        [$retarget, $reexpand, $move, $assign, $release, $destroy] = $cases;
        $this->holdOn($release, 'council.1', 'u0005');

        $this->retargetOn($retarget, $first($retarget, 'retargetNodes'), ['u0007']);
        $this->reexpandOn($reexpand, $first($reexpand, 'reexpandNodes', 1));
        $moveNode = $operationOf($move)['moveNodes'][0];
        $backTo = $label($move, (int) $moveNode['defaultBackNodeId']);
        $this->moveOn($move, $label($move, $moveNode['nodeId']), 'back', $backTo);
        $this->reassignOn($assign, $first($assign, 'assignNodes'), ['u0007']);
        // 保留解除は面のステップを案件の側の業務処理へ、処理担当者なし(管理者が自分として。u0004 は処理対象者でない)で送る
        $this->releaseOn($release, $first($release, 'releaseNodes'), self::MANAGER);
        $this->assertTrue($operationOf($destroy)['buttons']['destroy']);
        $this->deleteCaseAs($destroy);

        $operated = [$retarget, $reexpand, $move, $assign, $release];
        $actions = array_map(fn (WorkflowCase $case) => $this->latestRow($case)[0], $operated);
        $this->assertSame(['retarget', 'reexpand', 'move_back', 'reassign', 'release'], $actions);
        $this->assertFalse(WorkflowCase::query()->whereKey($destroy->id)->exists());
    }

    /**
     * R1 を予算 100(部長なし)で申請し、課長(u0002)が合議 = u0005・u0009、責任者 = u0010 を指定して承認した案件(合議の子 2 つが承認待ち)。
     */
    private function councilCase(): WorkflowCase
    {
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'section_chief', 'u0002', ['council' => ['u0005', 'u0009'], 'owner' => ['u0010']]);

        return $case;
    }

    /**
     * @return list<int>
     */
    private function idsAt(WorkflowCase $case, string ...$labels): array
    {
        $ids = $this->labelIds($case);

        return array_values(array_map(fn (string $label) => $ids[$label], $labels));
    }

    /**
     * @return array<string, mixed>
     */
    private function operationOf(string $userCode, WorkflowCase $case): array
    {
        [$canOperate, $operation] = $this->panelOf($userCode, $case);
        $this->assertTrue($canOperate);
        $this->assertNotNull($operation);

        return $operation;
    }

    /**
     * 詳細の [can.operate, operation]。
     *
     * @return array{bool, array<string, mixed>|null}
     */
    private function panelOf(string $userCode, WorkflowCase $case): array
    {
        $detail = app(CaseDetailQuery::class)->show($this->contextOf($this->seededUser($userCode)), $case->id);

        return [$detail['can']['operate'], $detail['operation']];
    }

    /**
     * 未完了で現在のノードが承認のノードの案件のボタン(保留解除は渡す)。
     *
     * @return array<string, bool>
     */
    private function buttons(bool $release): array
    {
        return [
            'retarget' => true, 'reexpand' => true, 'move' => true, 'assign' => true,
            'release' => $release, 'destroy' => true,
        ];
    }

    /**
     * 処理の時に指定のノード(差戻しできる先 = 申請)。
     */
    private function designated(
        string $code,
        string $name,
        WorkflowNodeKind $kind,
        string $by,
        int $min,
        int $max,
    ): RouteNodeInput {
        return new RouteNodeInput(
            $code, $name, $kind, WorkflowTargetKind::Designated,
            designatorCode: $by, minAssignees: $min, maxAssignees: $max, sendBackCodes: ['apply'],
        );
    }

    /**
     * 指定を変更できるステップの [id, 名前, 種類の語, 最小, 最大, 並びが処理の順か, 今の指定のコード]。
     *
     * @param  array<string, mixed>  $operation
     * @return list<list<mixed>>
     */
    private function assignRows(array $operation): array
    {
        return array_values(array_map(fn (array $node) => [
            $node['nodeId'], $node['name'], $node['kindLabel'], $node['min'], $node['max'], $node['ordered'],
            $this->codes($node['assigned']),
        ], $operation['assignNodes']));
    }

    /**
     * @param  list<array{id: int, code: string, name: string|null}>  $people
     * @return list<string>
     */
    private function codes(array $people): array
    {
        return array_column($people, 'code');
    }
}
