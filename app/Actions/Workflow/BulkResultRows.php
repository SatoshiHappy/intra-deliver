<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowBulkOutcome;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\CaseNumbers;
use App\Queries\Workflow\CaseOperationDecider;
use App\Queries\Workflow\CaseRows;
use App\Queries\Workflow\CaseVisibility;
use App\Support\AccessContext;

/**
 * 一括承認・一括確認の結果の行(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.7 節の「結果の分け方」と「結果の行の申請の情報」)。
 * 処理の後に、送られた案件をまとめて閲覧の判定に通し、閲覧できた案件だけが申請名・番号・フロー名を持つ(送られた id から情報を引かない)。
 * 「今の状態」は処理の後の状態を、単独の操作と同じ 1 か所({@see CaseOperationDecider}・{@see CaseVisibility})で読む。
 *
 * @phpstan-type Outcome array{WorkflowBulkOutcome, string|null}
 */
class BulkResultRows
{
    public const NO_ACTION_NEEDED = '対応は不要です。';

    public const APPROVE_AGAIN = 'もう一度承認してください。';

    public const OPEN_CASE = '申請を開いて処理してください。';

    public const CONFIRM_AGAIN = 'もう一度確認してください。';

    public function __construct(
        private CaseVisibility $visibility,
        private CaseOperationDecider $decider,
    ) {}

    /**
     * 一括承認の結果の行。止まった案件は、画面が古いだけ(改訂番号か組の違い)で今も選べれば「もう一度承認」、ほかは開いて処理する。
     *
     * @param  list<int>  $caseIds  送られた並び
     * @param  list<int>  $approvedIds  承認できた案件
     * @param  int|null  $stoppedId  止まった案件(止まらなければ null)
     * @param  bool  $isStale  止まった理由が画面の古さ(改訂番号か組の違い)か
     * @return list<BulkCaseResult>
     */
    public function approvals(
        AccessContext $context,
        array $caseIds,
        array $approvedIds,
        ?int $stoppedId,
        bool $isStale,
    ): array {
        $visible = $this->visibleCases($context, $caseIds);
        $stoppedCase = $stoppedId === null ? null : $visible[$stoppedId] ?? null;
        $stopped = $stoppedCase === null ? null : $this->stoppedOutcome($context, $stoppedCase, $isStale);

        return array_map(fn (int $caseId) => $this->row($caseId, $visible[$caseId] ?? null, match (true) {
            in_array($caseId, $approvedIds, true) => [WorkflowBulkOutcome::Approved, null],
            ! isset($visible[$caseId]) => [WorkflowBulkOutcome::Pending, null],
            $caseId === $stoppedId && $stopped !== null => $stopped,
            default => [WorkflowBulkOutcome::Pending, self::APPROVE_AGAIN],
        }), $caseIds);
    }

    /**
     * 一括確認の結果の行(確認は改訂番号を見ないので、やり直しの判定は要らない)。
     *
     * @param  list<int>  $caseIds  送られた並び
     * @param  list<int>  $confirmedIds  確認できた案件
     * @return list<BulkCaseResult>
     */
    public function confirmations(AccessContext $context, array $caseIds, array $confirmedIds): array
    {
        $visible = $this->visibleCases($context, $caseIds);
        $requester = Requester::fromAccessContext($context);
        $remaining = array_values(array_diff_key($visible, array_flip($confirmedIds)));
        $situations = $this->decider->situationsOf($requester, $context->date, $remaining);
        $canConfirm = fn (int $caseId) => $this->decider->canConfirm($requester, $context->date, $situations[$caseId]);

        return array_map(fn (int $caseId) => $this->row($caseId, $visible[$caseId] ?? null, match (true) {
            in_array($caseId, $confirmedIds, true) => [WorkflowBulkOutcome::Confirmed, null],
            ! isset($visible[$caseId]) => [WorkflowBulkOutcome::Pending, null],
            ! $canConfirm($caseId) => [WorkflowBulkOutcome::Processed, self::NO_ACTION_NEEDED],
            default => [WorkflowBulkOutcome::Pending, self::CONFIRM_AGAIN],
        }), $caseIds);
    }

    /**
     * 止まった案件の結果と対応(自分が当たる現在のノードの有無 = 未処理の述語、今も選べるか = 行の can.bulkApprove と同じ判定)。
     *
     * @return Outcome
     */
    private function stoppedOutcome(AccessContext $context, WorkflowCase $case, bool $isStale): array
    {
        $requester = Requester::fromAccessContext($context);
        $ofCase = WorkflowCase::query()->whereKey($case->id);
        $isPending = $this->visibility->pending($ofCase, $requester, $context->date)->exists();

        if (! $isPending) {
            return [WorkflowBulkOutcome::Processed, self::NO_ACTION_NEEDED];
        }

        $situations = array_values($this->decider->situationsOf($requester, $context->date, [$case]));
        $isSelectable = $this->decider->bulkApprovalsOf($requester, $context->date, $situations)[$case->id] !== null;

        return [WorkflowBulkOutcome::Pending, $isStale && $isSelectable ? self::APPROVE_AGAIN : self::OPEN_CASE];
    }

    /**
     * 結果を作る時に閲覧できる案件(フローを読み込む)。
     *
     * @param  list<int>  $caseIds
     * @return array<int, WorkflowCase> 案件の id → 案件
     */
    private function visibleCases(AccessContext $context, array $caseIds): array
    {
        $query = WorkflowCase::query()->with('workflowFlow')->whereIn('id', $caseIds);
        $cases = $this->visibility->visible($query, Requester::fromAccessContext($context), $context->date)->get();

        return $cases->keyBy('id')->all();
    }

    /**
     * @param  Outcome  $outcome
     */
    private function row(int $caseId, ?WorkflowCase $case, array $outcome): BulkCaseResult
    {
        $number = $case?->case_number === null ? null : CaseNumbers::format($case->case_number);
        $flowName = $case === null ? null : CaseRows::flowOf($case)->name;

        $name = $case === null ? null : CaseRows::nameOf($case);

        return new BulkCaseResult($caseId, $name, $number, $flowName, ...$outcome);
    }
}
