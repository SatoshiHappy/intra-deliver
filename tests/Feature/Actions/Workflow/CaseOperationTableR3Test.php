<?php

namespace Tests\Feature\Actions\Workflow;

use Tests\Support\ChecksRouteOperationTable;
use Tests\Support\TransactionalTestCase;

/**
 * 状態 × 操作の表(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 8 節、同時実行と検査.md 14 節)のうち、ルートの見本 R3(chief(B)が横配置 line を
 * 指定し、最後に final(R))の 2 つの状況の可否の全組。期待・記号・入力は {@see ChecksRouteOperationTable}。申請者承認防止のノードは R3 に無い。
 */
final class CaseOperationTableR3Test extends TransactionalTestCase
{
    use ChecksRouteOperationTable;

    /**
     * 横配置の子 2 が承認待ち(B が X・Y・Z を指定し、X が子 1 を承認。注目 = 子 2(Y))。X = 子 1 の承認を引戻せる直前の処理者、B = 以前の処理者。
     * 差戻し先 = 申請、引戻すノード = 子 1。
     */
    public function test_second_serial_child_waiting(): void
    {
        $this->addTableVersion($this->r3());
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'chief', $this->codeOf('B'), ['line' => [$this->codeOf('X'), $this->codeOf('Y'), $this->codeOf('Z')]]);
        $this->approve($case, 'line.1', $this->codeOf('X'));

        $checked = $this->assertOperationTable($case, [
            'roles' => ['currentTarget' => 'Y', 'lastActor' => 'X', 'previousActor' => 'B'],
            'visible' => 'ABRXY',
            'success' => $this->targetAndPullBack('currentTarget', 'lastActor'),
            'inputs' => $this->tableInputs($case, 'line.2', 'apply', 'line.1'),
        ]);

        $this->assertSame(9 * self::OPERATIONS, $checked);
    }

    /**
     * 指定 0 人で省いた後(横配置の最小を 0 にした版で、B が 0 人を指定して承認 → 横配置を省き、final が R の承認待ち。注目 = final)。final の処理対象者は
     * 参照者と同じ人なので、参照者の役割は持たない。B = chief の承認を引戻せる直前の処理者。差戻し先 = 申請、引戻すノード = chief。
     */
    public function test_after_skipping_a_node_with_zero_assignees(): void
    {
        $this->addTableVersion($this->r3(minAssignees: 0));
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'chief', $this->codeOf('B'), ['line' => []]);

        $checked = $this->assertOperationTable($case, [
            'roles' => ['referrer' => null, 'currentTarget' => 'R', 'lastActor' => 'B'],
            'visible' => 'ABR',
            'success' => $this->targetAndPullBack('currentTarget', 'lastActor'),
            'inputs' => $this->tableInputs($case, 'final', 'apply', 'chief'),
        ]);

        $this->assertSame(7 * self::OPERATIONS, $checked);
    }
}
