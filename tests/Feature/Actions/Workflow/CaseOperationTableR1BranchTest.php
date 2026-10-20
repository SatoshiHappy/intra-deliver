<?php

namespace Tests\Feature\Actions\Workflow;

use App\Models\Workflow\WorkflowCase;
use Tests\Support\ChecksRouteOperationTable;
use Tests\Support\TransactionalTestCase;

/**
 * 状態 × 操作の表(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 8 節、同時実行と検査.md 14 節)のうち、ルートの見本 R1 の分岐と差戻しの 4 つの状況の
 * 可否の全組。期待・記号・入力は {@see ChecksRouteOperationTable}。課長(B)は合議に X・Y・Z、責任者に O を指定して承認する。
 * 役割が重なる組(申請者 = 申請の直前の処理者など)と、その状況にいない役割は持たない。申請者承認防止のノードの処理対象者に入った申請者は R1 にいない
 * (課長・部長の展開に申請者は入らず、合議と責任者の指定は申請者を拒む = 処理対象者.md 5.2 の 5)。
 */
final class CaseOperationTableR1BranchTest extends TransactionalTestCase
{
    use ChecksRouteOperationTable;

    /**
     * 分岐の真の枝の承認待ち(予算 500。注目 = 部長(C の承認待ち))。B = 課長の承認を引戻せる直前の処理者。差戻し先 = 申請、引戻すノード = 課長。
     */
    public function test_waiting_on_the_true_branch(): void
    {
        $case = $this->caseAfterSectionChief(500);

        $checked = $this->assertOperationTable($case, [
            'roles' => ['currentTarget' => 'C', 'lastActor' => 'B'],
            'visible' => 'ABCR',
            'success' => $this->targetAndPullBack('currentTarget', 'lastActor'),
            'inputs' => $this->tableInputs($case, 'manager', 'apply', 'section_chief'),
        ]);

        $this->assertSame(8 * self::OPERATIONS, $checked);
    }

    /**
     * 偽の枝を省いた後(予算 100 = 部長を省いて合議へ。注目 = 合議の子 1(X)、ほかの子 = Y)。B = 課長の承認(合議を展開した)を引戻せる直前の処理者。
     * 差戻し先 = 申請、引戻すノード = 課長。
     */
    public function test_after_the_false_branch_is_skipped(): void
    {
        $case = $this->caseAfterSectionChief(100);

        $checked = $this->assertOperationTable($case, [
            'roles' => ['currentTarget' => 'X', 'otherChild' => 'Y', 'lastActor' => 'B'],
            'visible' => 'ABRXYZ',
            'success' => $this->targetAndPullBack('currentTarget', 'lastActor'),
            'inputs' => $this->tableInputs($case, 'council.1', 'apply', 'section_chief'),
        ]);

        $this->assertSame(9 * self::OPERATIONS, $checked);
    }

    /**
     * 分岐の前へ差戻した直後(予算 500。部長 C が課長へ差戻した。注目 = 課長(B の承認待ち = 指定する人))。C = 差戻しを引戻せる直前の処理者。
     * 指定 = 合議 X・Y(最小 2)、責任者 O(最小 1)。差戻し先 = 申請(課長の候補)、引戻すノード = 部長(差戻しの行のノード)。
     */
    public function test_right_after_a_send_back_before_the_branch(): void
    {
        $case = $this->caseAfterSectionChief(500);
        $this->sendBackOn($case, 'manager', $this->codeOf('C'), 'section_chief');

        $checked = $this->assertOperationTable($case, [
            'roles' => ['designator' => 'B', 'lastActor' => 'C'],
            'visible' => 'ABCR',
            'success' => $this->targetAndPullBack('designator', 'lastActor'),
            'inputs' => $this->tableInputs($case, 'section_chief', 'apply', 'manager', ['council' => 'XY', 'owner' => 'O']),
        ]);

        $this->assertSame(8 * self::OPERATIONS, $checked);
    }

    /**
     * 差戻しの引戻しの直後(上の状況で C が引戻した。注目 = 部長(C の承認待ち))。最新の行は引戻しなので引戻せる人はいない。B = 以前の処理者(課長の承認は
     * 差戻しの行が変えたので引戻せない)。差戻し先 = 申請、引戻すノード = 注目のノード。
     */
    public function test_right_after_pulling_back_a_send_back(): void
    {
        $case = $this->caseAfterSectionChief(500);
        $this->sendBackOn($case, 'manager', $this->codeOf('C'), 'section_chief');
        $this->pullBackOn($case, 'manager', $this->codeOf('C'));

        $checked = $this->assertOperationTable($case, [
            'roles' => ['currentTarget' => 'C', 'previousActor' => 'B'],
            'visible' => 'ABCR',
            'success' => $this->targetAndPullBack('currentTarget', null),
            'inputs' => $this->tableInputs($case, 'manager', 'apply', 'manager'),
        ]);

        $this->assertSame(8 * self::OPERATIONS, $checked);
    }

    /**
     * R1 を申請し(予算 = $budget)、課長が合議 X・Y・Z と責任者 O を指定して承認した案件。
     */
    private function caseAfterSectionChief(int $budget): WorkflowCase
    {
        $this->addTableVersion($this->r1());
        [$case] = $this->applyOnRoute(['budget' => $budget]);
        $this->approveSectionChief($case, 'XYZ');

        return $case;
    }
}
