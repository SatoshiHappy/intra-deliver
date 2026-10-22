<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ApproveCaseEarly;
use App\Models\Workflow\WorkflowCase;
use Tests\Support\ChecksCaseOperationTable;
use Tests\Support\ChecksRouteOperationTable;
use Tests\Support\TransactionalTestCase;

/**
 * 状態 × 操作の表(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 8 節、同時実行と検査.md 14 節)のうち、ルートの見本 R2(同期で枝 A = a1(B)→ a2(C)、
 * 枝 B = b1(D)を待ち、最後に final(R))の 3 つの状況の可否の全組。期待・記号・入力は {@see ChecksRouteOperationTable}。注目 = a1(どの状況も)。
 * 並列のほかの枝の処理対象者(D)が注目のノードを操作すると表に無い組(422 case)。申請者承認防止のノードは R2 に無い。
 *
 * @phpstan-import-type Result from ChecksCaseOperationTable
 */
final class CaseOperationTableR2Test extends TransactionalTestCase
{
    use ChecksRouteOperationTable;

    /**
     * 同期の 2 つの枝が両方承認待ち(申請の直後)。申請者 A = 申請を引戻せる直前の処理者。差戻し先 = 申請、引戻すノード = 申請。
     */
    public function test_both_sync_branches_waiting(): void
    {
        $case = $this->appliedCase();

        $checked = $this->assertOperationTable($case, [
            'roles' => ['currentTarget' => 'B', 'otherBranch' => 'D'],
            'visible' => 'ABDR',
            'success' => $this->targetAndPullBack('currentTarget', 'applicant'),
            'inputs' => $this->tableInputs($case, 'a1', 'apply', 'apply'),
            'manager' => $this->managedNode(),
        ]);

        $this->assertSame(8 * self::OPERATIONS, $checked);
    }

    /**
     * 同期の片方の枝が済んだ後(D が b1 を承認)。D = 並列の枝の引戻しできる処理者(自分の枝に誰も触れていない)。申請者 A は引戻せない(b1 の行が申請の子孫を変えた)。
     * 差戻し先 = 申請、引戻すノード = b1。
     */
    public function test_after_one_sync_branch_is_done(): void
    {
        $case = $this->appliedCase();
        $this->approve($case, 'b1', $this->codeOf('D'));

        $checked = $this->assertOperationTable($case, [
            'roles' => ['currentTarget' => 'B', 'branchPuller' => 'D'],
            'visible' => 'ABDR',
            'success' => $this->targetAndPullBack('currentTarget', 'branchPuller'),
            'inputs' => $this->tableInputs($case, 'a1', 'apply', 'b1'),
            'manager' => $this->managedNode(),
        ]);

        $this->assertSame(8 * self::OPERATIONS, $checked);
    }

    /**
     * 完了(B が a1 で承認終了し、並列の枝の b1 を省いた)。どの操作もできない。ノードを取る操作と引戻しは最新の行のノード(a1)、差戻し先 = 申請。
     * D = 省いたノードの処理対象者(u0004 = システム管理者なので、管理の述語で閲覧できる)。
     */
    public function test_completed_by_approving_early_with_a_parallel_branch(): void
    {
        $case = $this->appliedCase();
        $this->operateOn(ApproveCaseEarly::class, $case, 'a1', $this->codeOf('B'));

        $checked = $this->assertOperationTable($case, [
            'roles' => ['lastActor' => 'B', 'otherBranch' => 'D'],
            'visible' => 'ABDR',
            'success' => [],
            'inputs' => $this->tableInputs($case, 'a1', 'apply', 'a1'),
            'manager' => ['delete_case' => self::DELETED],
        ]);

        $this->assertSame(8 * self::OPERATIONS, $checked);
    }

    /**
     * 案件の管理者 D(システム管理者)が、注目の a1 の処理対象者の変更・再展開・ステップの移動(戻る = 申請へ・進む・終了)と案件の削除をできる
     * (設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.2 節。指定するノードが無いので指定の変更は 422)。
     *
     * @return array<string, self::ONLY_INVARIANTS|Result>
     */
    private function managedNode(): array
    {
        $onNode = ['retarget', 'reexpand', 'move_back', 'move_forward', 'move_finish'];

        return [...array_fill_keys($onNode, self::ONLY_INVARIANTS), 'delete_case' => self::DELETED];
    }

    /**
     * R2 を申請した案件(a1 = B、b1 = D の承認待ち)。
     */
    private function appliedCase(): WorkflowCase
    {
        $this->addTableVersion($this->r2());
        [$case] = $this->applyOnRoute([]);

        return $case;
    }
}
