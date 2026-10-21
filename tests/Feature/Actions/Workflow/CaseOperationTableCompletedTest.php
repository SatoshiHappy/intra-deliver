<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\ApproveCaseEarly;
use App\Actions\Workflow\RejectCase;
use App\Actions\Workflow\WithdrawCase;
use Tests\Support\ChecksCaseOperationTable;
use Tests\Support\TransactionalTestCase;

/**
 * 状態 × 操作の表(docs の 設計/ワークフロー/案件の操作/操作.md 3.2、同時実行と検査.md 7 節)のうち、完了の 4 つの状況(承認・承認終了・否認・取止め)の全組。
 * 完了の案件はどの操作もできない(閲覧できる人は 422 case。申請の系統は申請できない人なら 403 が先、閲覧できない人は 404)。期待と記号は {@see ChecksCaseOperationTable}。
 */
final class CaseOperationTableCompletedTest extends TransactionalTestCase
{
    use ChecksCaseOperationTable;

    /** 完了の案件の操作の数(一時保存(新規)を除く 14) */
    private const OPERATIONS = 14;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTableFlow();
    }

    /**
     * 承認(B・C が承認。直前の処理者 = 最後の承認の C、以前の処理者 = B)。
     */
    public function test_completed_by_approval(): void
    {
        $case = $this->applyAs($this->seededUser('u0001'));
        $this->operate(ApproveCase::class, $this->person('B'), $case);
        $this->operate(ApproveCase::class, $this->person('C'), $case);

        $checked = $this->assertOperationTable($case, ['roles' => ['currentTarget' => null, 'lastActor' => 'C', 'previousActor' => 'B'], 'visible' => 'ABCR', 'success' => []]);

        $this->assertSame(8 * self::OPERATIONS, $checked);
    }

    /**
     * 承認終了・否認(B が最新の行を持つが、引戻しの種別でないので直前の処理者でない。lastActor = その B)。
     */
    public function test_completed_by_approving_early_or_rejecting(): void
    {
        foreach ([ApproveCaseEarly::class, RejectCase::class] as $operation) {
            $case = $this->applyAs($this->seededUser('u0001'));
            $this->operate($operation, $this->person('B'), $case);

            $checked = $this->assertOperationTable($case, ['roles' => ['currentTarget' => null, 'lastActor' => 'B', 'previousActor' => null], 'visible' => 'ABR', 'success' => []]);

            $this->assertSame(7 * self::OPERATIONS, $checked);
        }
    }

    /**
     * 取止め(C が申請へ差し戻した後に A が取り止めた。以前の処理者 = B)。
     */
    public function test_completed_by_withdrawal(): void
    {
        $case = $this->applyAs($this->seededUser('u0001'));
        $this->operate(ApproveCase::class, $this->person('B'), $case);
        $this->sendBack($this->person('C'), $case, 'apply');
        $this->operate(WithdrawCase::class, $this->person('A'), $case);

        $checked = $this->assertOperationTable($case, ['roles' => ['currentTarget' => null, 'lastActor' => null, 'previousActor' => 'B'], 'visible' => 'ABCR', 'success' => []]);

        $this->assertSame(7 * self::OPERATIONS, $checked);
    }
}
