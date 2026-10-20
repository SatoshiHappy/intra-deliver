<?php

namespace Tests\Feature\Actions\Workflow;

use App\Models\Workflow\WorkflowCase;
use Tests\Support\ChecksRouteOperationTable;
use Tests\Support\TransactionalTestCase;

/**
 * 状態 × 操作の表(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 8 節、同時実行と検査.md 14 節)のうち、ルートの見本 R1 の合議の 4 つの状況の可否の全組。
 * 予算 100(部長を省く)で、課長(B)が合議に X・Y・Z(人数 2 で可決)、責任者に O を指定して承認した後から票を入れる。期待・記号・入力は {@see ChecksRouteOperationTable}。
 * 合議で票を入れた人(X)は、判定が付く前なら自分の票を引戻せる(10.5 の並列の枝・合議の票の引戻しの項)。申請者承認防止のノードの処理対象者に入った申請者は R1 にいない
 * ({@see CaseOperationTableR1BranchTest})。
 */
final class CaseOperationTableR1CouncilTest extends TransactionalTestCase
{
    use ChecksRouteOperationTable;

    /**
     * 合議の途中(3 人中 1 人承認。注目 = 子 2(Y)、ほかの子 = Z)。X = 票を引戻せる。B = 以前の処理者。差戻し先 = 申請、引戻すノード = 子 1。
     */
    public function test_council_in_progress_after_one_approval(): void
    {
        $case = $this->caseAtCouncil();
        $this->approve($case, 'council.1', $this->codeOf('X'));

        $this->assertCouncilInProgress($case);
    }

    /**
     * 合議の途中(3 人中 1 人否認・まだ可決できる。注目 = 子 2(Y)、ほかの子 = Z)。X = 否認票を引戻せる。B = 以前の処理者。差戻し先 = 申請、引戻すノード = 子 1。
     */
    public function test_council_in_progress_after_one_rejection(): void
    {
        $case = $this->caseAtCouncil();
        $this->reject($case, 'council.1', $this->codeOf('X'));

        $this->assertCouncilInProgress($case);
    }

    /**
     * 合議の可決の直後(X・Y の承認で可決、子 3 を省いた。注目 = 責任者(O の承認待ち))。Y = 可決を決めた票を引戻せる直前の処理者、X = 可決の前の票(引戻せない)、
     * Z = 省いた子の処理対象者(閲覧できなくなった)。差戻し先 = 申請、引戻すノード = 子 2。
     */
    public function test_right_after_the_council_passes(): void
    {
        $case = $this->caseAtCouncil();
        $this->approve($case, 'council.1', $this->codeOf('X'));
        $this->approve($case, 'council.2', $this->codeOf('Y'));

        $checked = $this->assertOperationTable($case, [
            'roles' => ['currentTarget' => 'O', 'lastActor' => 'Y', 'councilVoter' => 'X', 'previousActor' => 'B', 'otherChild' => 'Z'],
            'visible' => 'ABORXY',
            'success' => $this->targetAndPullBack('currentTarget', 'lastActor'),
            'inputs' => $this->tableInputs($case, 'owner', 'apply', 'council.2'),
        ]);

        $this->assertSame(11 * self::OPERATIONS, $checked);
    }

    /**
     * 合議が可決できなくなった完了(X・Y の否認で完了(否認))。どの操作もできない。現在のノードが無いので、ノードを取る操作と引戻しは最新の行のノード(子 2)、
     * 差戻し先 = 申請。Z = 省いた子の処理対象者(閲覧できない)。
     */
    public function test_completed_when_the_council_can_no_longer_pass(): void
    {
        $case = $this->caseAtCouncil();
        $this->reject($case, 'council.1', $this->codeOf('X'));
        $this->reject($case, 'council.2', $this->codeOf('Y'));

        $checked = $this->assertOperationTable($case, [
            'roles' => ['lastActor' => 'Y', 'councilVoter' => 'X', 'previousActor' => 'B', 'otherChild' => 'Z'],
            'visible' => 'ABRXY',
            'success' => [],
            'inputs' => $this->tableInputs($case, 'council.2', 'apply', 'council.2'),
        ]);

        $this->assertSame(10 * self::OPERATIONS, $checked);
    }

    /**
     * 合議の途中の 2 つの状況の組(子 1 の票の後)。
     */
    private function assertCouncilInProgress(WorkflowCase $case): void
    {
        $checked = $this->assertOperationTable($case, [
            'roles' => ['currentTarget' => 'Y', 'otherChild' => 'Z', 'councilVoter' => 'X', 'previousActor' => 'B'],
            'visible' => 'ABRXYZ',
            'success' => $this->targetAndPullBack('currentTarget', 'councilVoter'),
            'inputs' => $this->tableInputs($case, 'council.2', 'apply', 'council.1'),
        ]);

        $this->assertSame(10 * self::OPERATIONS, $checked);
    }

    /**
     * R1(予算 100)を申請し、課長が合議 X・Y・Z と責任者 O を指定して承認した案件(合議の子 3 つが承認待ち)。
     */
    private function caseAtCouncil(): WorkflowCase
    {
        $this->addTableVersion($this->r1());
        [$case] = $this->applyOnRoute(['budget' => 100]);
        $this->approveSectionChief($case, 'XYZ');

        return $case;
    }
}
