<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\BulkResultRows;
use App\Actions\Workflow\CaseOperationErrors;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseConfirmation;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\RunsBulkOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 一括確認(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.7 節、同時実行と検査.md の 13 節・14.2 節)。
 * フロー = 申請(u0001)→ first(u0002。確認する人 = 経理部の u0010)→ second(u0007)。first の承認で u0010 の確認が開く。
 */
final class BulkConfirmCasesTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use RunsBulkOperations;

    private const AGAIN = BulkResultRows::CONFIRM_AGAIN;

    private const DONE = BulkResultRows::NO_ACTION_NEEDED;

    protected function setUp(): void
    {
        parent::setUp();
        $this->addVersion($this->confirmingRoute());
    }

    /**
     * 並びの順に 1 件ずつ確認し、確認できる確認の無い案件(差戻しで閉じた)で止まる(前は確定、後は処理しない)。確認は通知を作らない。
     * 確認済みの案件も、もう一度確認できる。
     */
    public function test_confirms_in_order_and_stops_at_the_first_failure(): void
    {
        [$first, $closed, $last] = [$this->opened(), $this->opened(), $this->opened()];
        $this->sendBack($this->seededUser('u0007'), $closed, 'apply');
        $this->confirmAs($first, 'u0010');
        $before = $this->workflowCounts();

        $result = $this->bulkConfirm($this->seededUser('u0010'), [$first->id, $closed->id, $last->id]);

        $this->assertSame([
            [$first->id, 'confirmed', null, true],
            [$closed->id, 'processed', self::DONE, true],
            [$last->id, 'pending', self::AGAIN, true],
        ], $this->resultLines($result));
        $this->assertSame([3, 1], [$result->total(), $result->succeeded()]);
        $counts = array_map(fn (WorkflowCase $case) => $this->confirmations($case), [$first, $closed, $last]);
        $this->assertSame([2, 0, 0], $counts);
        $this->assertSame([...$before, 'confirmations' => $before['confirmations'] + 1], $this->workflowCounts());
    }

    /**
     * 結果の分け方の枝: 閲覧できない・無い(申請名を持たない)、完了した・確認対象者でない(処理済み)。止まった後ろの閲覧できない案件・
     * 消された案件の行も申請名を持たない。
     */
    public function test_each_branch_of_the_result(): void
    {
        $missing = (int) WorkflowCase::query()->max('id') + 1;
        [$open, $completed] = [$this->opened(), $this->opened()];
        $this->operate(ApproveCase::class, $this->seededUser('u0007'), $completed);

        $results = [
            $this->resultLines($this->bulkConfirm($this->seededUser('u0006'), [$open->id, $missing])),
            $this->resultLines($this->bulkConfirm($this->seededUser('u0010'), [$completed->id, $open->id, $missing])),
            $this->resultLines($this->bulkConfirm($this->seededUser('u0007'), [$open->id])),
        ];

        $this->assertSame([
            [[$open->id, 'pending', null, false], [$missing, 'pending', null, false]],
            [
                [$completed->id, 'processed', self::DONE, true],
                [$open->id, 'pending', self::AGAIN, true],
                [$missing, 'pending', null, false],
            ],
            [[$open->id, 'processed', self::DONE, true]],
        ], $results);
        $this->assertSame(0, $this->confirmations($open));
    }

    /**
     * 選択の誤り(0 件・21 件・重複・形の違い)は 422 case_ids で、どの案件も処理しない。
     */
    public function test_input_errors_process_nothing(): void
    {
        $case = $this->opened();
        $required = ['case_ids' => CaseOperationErrors::BULK_REQUIRED];
        $inputs = [
            '0 件' => [[], $required],
            '21 件' => [range(1, 21), ['case_ids' => CaseOperationErrors::BULK_TOO_MANY]],
            '重複' => [[$case->id, $case->id], $required],
            '文字列' => [[(string) $case->id], $required],
            '並びでない' => [['a' => $case->id], $required],
            '配列でない' => [$case->id, $required],
        ];
        $before = $this->workflowCounts();

        foreach ($inputs as $label => [$caseIds, $expected]) {
            $errors = $this->validationErrors(fn () => $this->bulkConfirm($this->seededUser('u0010'), $caseIds));
            $this->assertSame($expected, $errors, $label);
        }

        $this->assertSame($before, $this->workflowCounts());
    }

    /**
     * 申請して first を承認した案件(u0010 の確認が開いている)。
     */
    private function opened(): WorkflowCase
    {
        $case = $this->applyAs($this->seededUser('u0001'));
        $this->operate(ApproveCase::class, $this->seededUser('u0002'), $case);

        return $case;
    }

    private function confirmations(WorkflowCase $case): int
    {
        return WorkflowCaseConfirmation::query()->where('workflow_case_id', $case->id)->count();
    }
}
