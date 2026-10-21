<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Authz\Api\CreateSelectionSubjectGroup;
use App\Actions\Workflow\ApproveCaseEarly;
use App\Actions\Workflow\ConfirmCase;
use App\Models\Workflow\WorkflowCase;
use Tests\Support\ChecksRouteOperationTable;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 状態 × 操作の表(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 8 節、同時実行と検査.md の 14.1 節)のうち、確認と連絡の行。
 * ルートの見本 R1(予算 100)の課長に確認する人 1 人(R = 経理部の u0010。フローの参照者で閲覧できる)を付けた形で、確認が開いた後・確認した後・
 * 差戻しで確認が閉じた後・完了(確認が開いていた)の 4 つの状況の可否の全組。期待・記号・入力は {@see ChecksRouteOperationTable} に足して:
 * 確認対象者 = R(役割 referrer)、確認対象者でない閲覧者 = A・B など、E = 承認の代理先(u0016。合議の子 1 の X の代理先として X の操作をし、
 * R の代理先でもある = 代理元の確認をできない)。連絡する人 = U(連絡を付けられる操作の成功の組で連絡の通知が 1 つできる)。成功の組は不変の条件だけ。
 */
final class CaseOperationTableConfirmTest extends TransactionalTestCase
{
    use ChecksRouteOperationTable;
    use RegistersProxies;

    /** 承認待ちのノードの処理対象者の操作 */
    private const TARGET_OPERATIONS = ['approve', 'approve_early', 'reject', 'send_back', 'hold', 'transfer'];

    /** 課長の承認の指定(合議 = X・Y・Z、責任者 = O) */
    private const DESIGNATION = ['council' => 'XYZ', 'owner' => 'O'];

    /** 承認待ちの処理対象者(X)と、X として処理する代理先(E) */
    private const TARGET_AND_PROXY = ['currentTarget', 'approvalProxy'];

    /** 申請の系統の操作を代理元(承認の代理)として送ると 422 principal_user_id */
    private const APPLY_KIND = ['reapply' => '422:principal_user_id', 'withdraw' => '422:principal_user_id'];

    /**
     * @return array<string, string|null>
     */
    protected function tablePeople(): array
    {
        return [...self::PEOPLE, ...self::ROUTE_PEOPLE, 'E' => 'u0016'];
    }

    /**
     * 確認が開いた後(課長 B が合議 X・Y・Z を指定して承認。注目 = 合議の子 1(X))。R が確認でき、B は課長を引戻せる。
     */
    public function test_after_the_confirmation_opened(): void
    {
        $case = $this->caseWithOpenConfirmation();

        $this->assertSame(10 * self::OPERATIONS, $this->assertOpenTable($case));
    }

    /**
     * 確認した後(R が確認済。何度でも確認できる)。ほかは開いた後と同じ。
     */
    public function test_after_the_target_confirmed(): void
    {
        $case = $this->caseWithOpenConfirmation();
        app(ConfirmCase::class)->execute($this->contextOf($this->person('R')), $case->id);

        $this->assertSame(10 * self::OPERATIONS, $this->assertOpenTable($case));
    }

    /**
     * 差戻しで確認が閉じた後(X が合議の子 1 から課長へ差戻した。注目 = 課長(B))。確認は誰もできず、X(と X の代理先 E)は差戻しを引戻せる。
     * E が X として課長を処理すると、X は課長の処理対象者でないので 422 principal_user_id。課長の承認は合議と責任者を指定する。
     */
    public function test_after_a_send_back_closed_the_confirmation(): void
    {
        $case = $this->caseWithOpenConfirmation();
        $this->sendBackOn($case, 'council.1', $this->codeOf('X'), 'section_chief');
        $principal = array_fill_keys(self::TARGET_OPERATIONS, '422:principal_user_id');

        $checked = $this->assertOperationTable($case, [
            'roles' => ['currentTarget' => 'B', 'lastActor' => 'X', 'approvalProxy' => 'E', 'otherChild' => 'Y'],
            'visible' => 'ABERX',
            'success' => [
                ...$this->successBy(array_fill_keys(self::TARGET_OPERATIONS, 'currentTarget')),
                'pull_back' => [['lastActor', 'approvalProxy'], self::ONLY_INVARIANTS],
            ],
            'principals' => ['approvalProxy' => 'X'],
            'rejections' => ['approvalProxy' => [...$principal, ...self::APPLY_KIND]],
            'inputs' => [
                ...$this->tableInputs($case, 'section_chief', 'apply', 'council.1', self::DESIGNATION),
                'contact' => 'U',
            ],
        ]);

        $this->assertSame(10 * self::OPERATIONS, $checked);
    }

    /**
     * 完了(確認が開いていた。X が合議の子 1 で承認終了)。完了の後は確認できず、どの操作もできない。
     */
    public function test_completed_while_the_confirmation_was_open(): void
    {
        $case = $this->caseWithOpenConfirmation();
        $this->operateOn(ApproveCaseEarly::class, $case, 'council.1', $this->codeOf('X'));

        $checked = $this->assertOperationTable($case, [
            'roles' => ['lastActor' => 'X', 'previousActor' => 'B', 'approvalProxy' => 'E', 'otherChild' => 'Y'],
            'visible' => 'ABERX',
            'success' => [],
            'principals' => ['approvalProxy' => 'X'],
            'rejections' => ['approvalProxy' => self::APPLY_KIND],
            'inputs' => [...$this->tableInputs($case, 'council.1', 'apply', 'council.1'), 'contact' => 'U'],
        ]);

        $this->assertSame(10 * self::OPERATIONS, $checked);
    }

    /**
     * 確認が開いている状況の組(開いた後・確認した後): X とその代理先 E の処理、B の引戻し、R の確認。
     */
    private function assertOpenTable(WorkflowCase $case): int
    {
        return $this->assertOperationTable($case, [
            'roles' => ['currentTarget' => 'X', 'otherChild' => 'Y', 'previousActor' => 'B', 'approvalProxy' => 'E'],
            'visible' => 'ABERXYZ',
            'success' => [
                ...array_fill_keys(self::TARGET_OPERATIONS, [self::TARGET_AND_PROXY, self::ONLY_INVARIANTS]),
                'pull_back' => ['previousActor', self::ONLY_INVARIANTS],
                'confirm' => ['referrer', self::ONLY_INVARIANTS],
            ],
            'principals' => ['approvalProxy' => 'X'],
            'rejections' => ['approvalProxy' => self::APPLY_KIND],
            'inputs' => [...$this->tableInputs($case, 'council.1', 'apply', 'section_chief'), 'contact' => 'U'],
        ]);
    }

    /**
     * 課長に確認する人 R を付けた R1 を申請し、課長が合議 X・Y・Z と責任者 O を指定して承認した案件(R の確認が開いた)。
     * E は X の承認の代理先で、R の代理先でもある。
     */
    private function caseWithOpenConfirmation(): WorkflowCase
    {
        $confirmer = [$this->userCondition($this->codeOf('R'))];
        $group = app(CreateSelectionSubjectGroup::class)->execute($confirmer, ['ja' => '確認する人']);
        $this->addTableVersion($this->r1($group));
        $proxy = $this->seededUser($this->codeOf('E'));
        $this->addProxy($this->seededUser($this->codeOf('X')), $proxy);
        $this->addProxy($this->seededUser($this->codeOf('R')), $proxy);
        [$case] = $this->applyOnRoute(['budget' => 100]);
        $this->approveSectionChief($case, 'XYZ');

        return $case;
    }
}
