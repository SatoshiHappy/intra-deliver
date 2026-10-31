<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\RouteDefinitionViolated;
use App\Actions\Workflow\RouteEdgeInput;
use App\Actions\Workflow\RouteNodeInput;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowRelativeBase;
use App\Enums\Workflow\WorkflowRelativeScope;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowRouteEdge;
use App\Models\Workflow\WorkflowRouteSendBack;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\SavesNodeSettings;
use Tests\Support\TransactionalTestCase;

/**
 * ステップの設定の保存の後の定義の検査(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md 3 節・6 節・8 節)。保存したノードの役ごとのキー。
 * 期待の文言は 6 節の表から書いた。どれも何も変えない。
 */
final class NodeSettingsDefinitionTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use SavesNodeSettings;

    private const DESIGNATOR = '処理対象者を指定するステップには、このステップより前の、必ず通り省略されない承認のステップを選択してください';

    private const SEND_BACK = '差戻しできる先には、このステップに至る経路上の、申請または承認のステップ(横配置・縦配置を含む)を選択してください';

    /**
     * G-9: 前処理者を起点にする指定を合流の直後に置く(Y の保存 = relative_base)。たどる途中のノードの最小を 0 に変える(Z の保存 = min_assignees)。
     */
    public function test_the_previous_base_rule(): void
    {
        $previous = ['targetKind' => WorkflowTargetKind::Relative, 'targets' => null,
            'relativeBase' => WorkflowRelativeBase::Previous, 'relativeScope' => WorkflowRelativeScope::Own];
        $joined = $this->addVersion($this->r2());
        $this->assertErrors(['relative_base' => 'このステップの直前は合流か合議のため、前の承認者を基準にできません'], $joined, 'final', $previous);

        $passing = $this->addVersion($this->passingRoute(), '2026-06-01');
        $this->assertErrors(['min_assignees' => 'このステップの後に前の承認者を基準にするステップがあるため、人数の最小は 1 以上にしてください'], $passing, 'mid', [
            'minAssignees' => 0,
        ]);
    }

    /**
     * G-10(Y の保存 = designator_node_code): 指定するノードが通らない枝にある・後ろにある・縦配置である・最小 0 の動的承認である。
     * X の保存(指定するノードの最小を 0 に変える)= min_assignees。
     */
    public function test_the_designator_rule(): void
    {
        $r1 = $this->addVersion($this->r1());
        $designator = ['designator_node_code' => self::DESIGNATOR];
        $this->assertErrors($designator, $r1, 'owner', ['designatorNodeCode' => 'manager']);
        $this->assertErrors($designator, $r1, 'owner', ['designatorNodeCode' => 'council']);
        $r3 = $this->addVersion($this->r3(), '2026-03-01');
        $this->assertErrors($designator, $r3, 'chief', $this->designatedBy('final'));

        $designated = $this->addVersion($this->designatedRoute(), '2026-06-01');
        $byX = $this->designatedBy('x');
        $this->assertErrors($designator, $designated, 'y', $byX);

        $this->saveSettings($designated, 'x', ['minAssignees' => 1]);
        $this->saveSettings($designated, 'y', $byX);
        $this->assertErrors(['min_assignees' => 'このステップはほかのステップの処理対象者を指定するため、人数の最小は 1 以上にしてください'], $designated, 'x', [
            'minAssignees' => 0,
        ]);
    }

    /**
     * G-11: 差戻しできる先が後ろのノード(差戻し元の保存 = send_back_node_codes)。違う 2 つの違反が保存したノードに当たればキーは 2 つ。
     */
    public function test_the_send_back_rule_and_two_keys_from_two_violations(): void
    {
        $r1 = $this->addVersion($this->r1());

        $sendBack = ['send_back_node_codes' => self::SEND_BACK];
        $this->assertErrors($sendBack, $r1, 'section_chief', ['sendBackNodeCodes' => ['apply', 'manager']]);
        $this->assertErrors(['designator_node_code' => self::DESIGNATOR, ...$sendBack], $r1, 'owner', [
            'designatorNodeCode' => 'manager', 'sendBackNodeCodes' => ['owner'],
        ]);
    }

    /**
     * 保存したノードが関わらない違反・差戻しの先だけの違反(起きない)・G-2〜G-7 は 422 より先に LogicException(定義の管理.md の 3 節)。
     */
    public function test_other_violations_are_a_logic_error_and_win_over_the_422(): void
    {
        $r1 = $this->addVersion($this->r1());
        $owner = $this->routeNode($r1, 'owner')->id;
        $chief = $this->routeNode($r1, 'section_chief')->id;
        $sendBack = WorkflowRouteSendBack::query()->create([
            'workflow_flow_version_id' => $r1, 'workflow_route_node_id' => $chief,
            'target_workflow_route_node_id' => $owner,
        ]);
        $this->assertViolated($r1, 'manager', []);
        $this->assertViolated($r1, 'owner', []);
        $sendBack->delete();

        WorkflowRouteEdge::query()->where('workflow_flow_version_id', $r1)->where('sort_key', 1)
            ->update(['condition' => json_encode(['any' => []])]);
        $this->assertViolated($r1, 'section_chief', []);
        $this->assertViolated($r1, 'owner', ['designatorNodeCode' => 'manager']);
    }

    /**
     * @param  array<string, string>  $expected
     * @param  array<string, mixed>  $overrides
     */
    private function assertErrors(array $expected, int $versionId, string $nodeCode, array $overrides): void
    {
        $before = $this->settingsSnapshot();
        $errors = $this->validationErrors(fn () => $this->saveSettings($versionId, $nodeCode, $overrides));

        ksort($expected);
        ksort($errors);
        $this->assertSame($expected, $errors, $nodeCode);
        $this->assertSame($before, $this->settingsSnapshot());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function assertViolated(int $versionId, string $nodeCode, array $overrides): void
    {
        $before = $this->settingsSnapshot();
        $save = fn () => $this->saveSettings($versionId, $nodeCode, $overrides);

        $this->assertThrows($save, RouteDefinitionViolated::class);
        $this->assertSame($before, $this->settingsSnapshot());
    }

    /**
     * 処理の時に指定(1〜1 人)へ変える入力。
     *
     * @return array<string, mixed>
     */
    private function designatedBy(string $designatorCode): array
    {
        return [
            'targetKind' => WorkflowTargetKind::Designated, 'targets' => null,
            'designatorNodeCode' => $designatorCode, 'minAssignees' => 1, 'maxAssignees' => 1,
        ];
    }

    /**
     * 申請 → chief(u0002)→ 同期(a1(u0003)/ b1(u0004))→ mid(chief が指定、1〜1 人)→ y(前処理者を起点)。
     *
     * @return array{list<RouteNodeInput>, list<RouteEdgeInput>}
     */
    private function passingRoute(): array
    {
        return [[
            $this->applyNode(),
            $this->approveNode('chief', 'u0002'),
            new RouteNodeInput('sync', '同期', WorkflowNodeKind::SyncStart, closingCode: 'sync_end'),
            $this->approveNode('a1', 'u0003'),
            $this->approveNode('b1', 'u0004'),
            new RouteNodeInput('sync_end', '同期終了', WorkflowNodeKind::SyncEnd),
            new RouteNodeInput(
                'mid', '中間', WorkflowNodeKind::Approve, WorkflowTargetKind::Designated,
                designatorCode: 'chief', minAssignees: 1, maxAssignees: 1,
            ),
            new RouteNodeInput(
                'y', '前の承認者', WorkflowNodeKind::Approve, WorkflowTargetKind::Relative,
                relativeBase: WorkflowRelativeBase::Previous, relativeScope: WorkflowRelativeScope::Own,
            ),
        ], $this->chain(
            ['apply', 'chief'], ['chief', 'sync'], ['sync', 'a1'], ['sync', 'b1'],
            ['a1', 'sync_end'], ['b1', 'sync_end'], ['sync_end', 'mid'], ['mid', 'y'],
        )];
    }

    /**
     * 申請 → chief(u0002)→ x(chief が指定、0〜1 人)→ y(u0003)。
     *
     * @return array{list<RouteNodeInput>, list<RouteEdgeInput>}
     */
    private function designatedRoute(): array
    {
        return [[
            $this->applyNode(),
            $this->approveNode('chief', 'u0002'),
            new RouteNodeInput(
                'x', '指定する承認', WorkflowNodeKind::Approve, WorkflowTargetKind::Designated,
                designatorCode: 'chief', minAssignees: 0, maxAssignees: 1,
            ),
            $this->approveNode('y', 'u0003'),
        ], $this->chain(['apply', 'chief'], ['chief', 'x'], ['x', 'y'])];
    }
}
