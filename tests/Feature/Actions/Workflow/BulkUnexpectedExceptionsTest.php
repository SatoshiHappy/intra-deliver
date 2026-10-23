<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\CaseContact;
use App\Actions\Workflow\CaseOperationResult;
use App\Actions\Workflow\SendCaseNotices;
use App\Enums\Workflow\WorkflowNoticeType;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseConfirmation;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use RuntimeException;
use Tests\Support\RunsBulkOperations;
use Tests\Support\TransactionalTestCase;
use Throwable;

/**
 * 一括承認・一括確認の中の想定外の例外(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.7 節)。止めるのは案件の 404 と
 * 判定の 422 だけで、ほかは一括ごと投げる(案件でないモデルの 404 は LogicException に包む)。前の案件は確定し、例外の出た案件は何も書かない。
 * フロー = 申請(u0001)→ first(u0002。確認する人 = 経理部の u0010)→ second(u0007)。
 */
final class BulkUnexpectedExceptionsTest extends TransactionalTestCase
{
    use RunsBulkOperations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->addVersion($this->confirmingRoute());
    }

    /**
     * 一括承認: 例外は通知の 1 か所から起こす。例外の出た案件の履歴・通知は残らない。
     */
    public function test_an_unexpected_exception_fails_the_bulk_approval_after_the_earlier_cases(): void
    {
        foreach ($this->unexpectedExceptions(WorkflowCaseHistory::class) as $label => [$exception, $expected]) {
            [$applicant, $approver] = [$this->seededUser('u0001'), $this->seededUser('u0002')];
            [$first, $failing] = [$this->applyAs($applicant), $this->applyAs($applicant)];
            $rows = [$this->screenRowOf($first, $approver), $this->screenRowOf($failing, $approver)];
            $this->failNoticesOf($failing, $exception);
            $before = [$this->historyCountOf($failing), $this->revisionOf($failing)];
            $lastNotice = $this->lastNoticeId();

            $this->assertThrows(fn () => $this->bulkApprove($approver, $rows), $expected);

            $this->assertSame($before, [$this->historyCountOf($failing), $this->revisionOf($failing)], $label);
            [$confirmStep, $second] = [['step_name' => 'first'], ['step_name' => 'second', 'step_kind' => 'approve']];
            $this->assertSame(self::sortedLines([
                $this->caseNotice(WorkflowNoticeType::ConfirmRequest, 'u0010', 'u0002', $first, $confirmStep),
                $this->caseNotice(WorkflowNoticeType::ProcessRequest, 'u0007', 'u0002', $first, $second),
            ]), $this->noticesSince($lastNotice), $label);
        }
    }

    /**
     * 一括確認: 例外は確認の行を書くところから起こす。例外の出た案件の確認の行は残らない。
     */
    public function test_an_unexpected_exception_fails_the_bulk_confirmation_after_the_earlier_cases(): void
    {
        foreach ($this->unexpectedExceptions(WorkflowCaseNode::class) as $label => [$exception, $expected]) {
            [$first, $failing] = [$this->opened(), $this->opened()];
            $this->failConfirmationsOf($failing, $exception);

            $confirm = fn () => $this->bulkConfirm($this->seededUser('u0010'), [$first->id, $failing->id]);
            $this->assertThrows($confirm, $expected);

            $counts = [$this->confirmationCountOf($first), $this->confirmationCountOf($failing)];
            $this->assertSame([1, 0], $counts, $label);
        }
    }

    /**
     * 想定外の例外の名前 → [投げる例外, 出る例外の確かめ]。案件でないモデル $model の 404 は、包んだ LogicException の前の例外として出る。
     *
     * @param  class-string<WorkflowCaseHistory|WorkflowCaseNode>  $model
     * @return array<string, array{Throwable, Closure(Throwable): bool}>
     */
    private function unexpectedExceptions(string $model): array
    {
        $missing = (new ModelNotFoundException)->setModel($model, [0]);
        $other = new RuntimeException('書けなかった');

        return [
            '案件でないモデルの 404' => [
                $missing,
                fn (Throwable $thrown) => $thrown instanceof LogicException && $thrown->getPrevious() === $missing,
            ],
            'ほかの例外' => [$other, fn (Throwable $thrown) => $thrown === $other],
        ];
    }

    /**
     * 通知の 1 か所を、$case の操作のときだけ $exception を投げるものに替える(ほかの案件は本物で送る)。
     */
    private function failNoticesOf(WorkflowCase $case, Throwable $exception): void
    {
        $real = $this->app->build(SendCaseNotices::class);
        $this->app->instance(SendCaseNotices::class, new class($real, $case->id, $exception) extends SendCaseNotices
        {
            public function __construct(
                private SendCaseNotices $real,
                private int $failingCaseId,
                private Throwable $exception,
            ) {}

            public function execute(CaseOperationResult $result, ?CaseContact $contact, CarbonImmutable $date): void
            {
                if ($result->caseId === $this->failingCaseId) {
                    throw $this->exception;
                }

                $this->real->execute($result, $contact, $date);
            }
        });
    }

    /**
     * $case の確認の行を書くときだけ $exception を投げる。
     */
    private function failConfirmationsOf(WorkflowCase $case, Throwable $exception): void
    {
        WorkflowCaseConfirmation::creating(function (WorkflowCaseConfirmation $row) use ($case, $exception): void {
            if ($row->workflow_case_id === $case->id) {
                throw $exception;
            }
        });
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

    private function historyCountOf(WorkflowCase $case): int
    {
        return WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->count();
    }

    private function confirmationCountOf(WorkflowCase $case): int
    {
        return WorkflowCaseConfirmation::query()->where('workflow_case_id', $case->id)->count();
    }
}
