<?php

namespace Tests\Feature\Actions\Workflow;

use App\Models\Workflow\WorkflowCase;
use Tests\Support\ChecksCaseOperationTable;
use Tests\Support\ChecksRouteOperationTable;
use Tests\Support\OperatesAsCaseManager;
use Tests\Support\TransactionalTestCase;

/**
 * 状態 × 操作の表(docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.2 節、同時実行と検査.md の 14.1 節)のうち、案件の管理者の行を
 * 指定するノードのある R1 で確かめる 3 つの状況(課長の承認の後 = 合議の子が承認待ち・責任者は到達していない / 指定の変更の後 / 処理対象者の変更の後)。
 * 期待・記号・入力は {@see ChecksRouteOperationTable}(案件の管理者 = D = システム管理者)。課長(B)は合議に X・Y・Z、責任者に O を指定して承認した。
 * 注目 = 合議の子 1、差戻し先 = 申請、引戻すノード = 課長、指定の変更のノード = 責任者(人の既定 = U)。管理者でない閲覧できる人(参照者 R・処理者)の
 * 案件操作は 403、閲覧できない人は 404。案件操作の行の後は、課長(B)は課長を引戻せない(案件操作が子孫を変えた = 15.2 節の「引戻し」)。
 *
 * @phpstan-import-type Result from ChecksCaseOperationTable
 */
final class CaseOperationTableManagerTest extends TransactionalTestCase
{
    use ChecksRouteOperationTable;
    use OperatesAsCaseManager;

    /**
     * 課長の承認の直後: 合議の子 1(X)の処理対象者の 6 つ、課長 B の引戻し、管理者 D の処理対象者の変更・再展開・ステップの移動・指定の変更・
     * 案件の削除が通る。
     */
    public function test_after_the_section_chief_designated_the_council_and_the_owner(): void
    {
        $case = $this->designatedCase();

        $checked = $this->assertOperationTable($case, [
            'roles' => ['caseManager' => 'D', 'currentTarget' => 'X', 'otherChild' => 'Y', 'designator' => 'B'],
            'visible' => 'ABDRXYZ',
            'success' => $this->targetAndPullBack('currentTarget', 'designator'),
            'inputs' => $this->managerInputs($case),
            'manager' => $this->managerOutcomes(),
        ]);

        $this->assertSame(10 * self::OPERATIONS, $checked);
    }

    /**
     * 管理者が責任者の指定を U に変えた後: 管理者は何度でも変えられる。課長 B は引戻せなくなる。
     */
    public function test_after_the_owner_was_reassigned(): void
    {
        $case = $this->designatedCase();
        $this->reassignOn($case, 'owner', [$this->codeOf('U')]);

        $checked = $this->assertOperationTable($case, [
            'roles' => ['caseManager' => 'D', 'currentTarget' => 'X', 'otherChild' => 'Y', 'designator' => 'B'],
            'visible' => 'ABDRXYZ',
            'success' => $this->targetAndPullBack('currentTarget', null),
            'inputs' => $this->managerInputs($case),
            'manager' => $this->managerOutcomes(),
        ]);

        $this->assertSame(10 * self::OPERATIONS, $checked);
    }

    /**
     * 管理者が合議の子 1 の処理対象者を X から O に変えた後: O が子 1 を処理でき、X は閲覧できなくなる。再展開で X に戻せる。
     */
    public function test_after_the_council_child_was_retargeted(): void
    {
        $case = $this->designatedCase();
        $this->retargetOn($case, 'council.1', [$this->codeOf('O')]);

        $checked = $this->assertOperationTable($case, [
            'roles' => [
                'caseManager' => 'D', 'currentTarget' => 'O', 'otherChild' => 'Y', 'designator' => 'B',
                'formerTarget' => 'X',
            ],
            'visible' => 'ABDORYZ',
            'success' => $this->targetAndPullBack('currentTarget', null),
            'inputs' => $this->managerInputs($case),
            'manager' => $this->managerOutcomes(),
        ]);

        $this->assertSame(11 * self::OPERATIONS, $checked);
    }

    /**
     * 管理者 D の成功: 注目の子 1 の処理対象者の変更(U へ)・再展開・ステップの移動(戻る = 申請へ・進む = 合議の票・終了)、責任者の指定の変更(U へ)、
     * 案件の削除。
     *
     * @return array<string, self::ONLY_INVARIANTS|Result>
     */
    private function managerOutcomes(): array
    {
        return [
            ...array_fill_keys(
                ['retarget', 'reexpand', 'move_back', 'move_forward', 'move_finish', 'reassign'],
                self::ONLY_INVARIANTS,
            ),
            'delete_case' => self::DELETED,
        ];
    }

    /**
     * @return array{node: int, sendBackTo: int, pullBack: int, assignments: array<int, list<int>>, reassign: int}
     */
    private function managerInputs(WorkflowCase $case): array
    {
        $inputs = $this->tableInputs($case, 'council.1', 'apply', 'section_chief');

        return [...$inputs, 'reassign' => $this->labelIds($case)['owner']];
    }

    /**
     * R1(予算 100)を申請し、課長 B が合議 X・Y・Z と責任者 O を指定して承認した案件。
     */
    private function designatedCase(): WorkflowCase
    {
        $this->addTableVersion($this->r1());
        [$case] = $this->applyOnRoute(['budget' => 100]);
        $this->approveSectionChief($case, 'XYZ');

        return $case;
    }
}
