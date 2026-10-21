<?php

namespace Tests\Support;

use App\Actions\Workflow\BulkApproveCases;
use App\Actions\Workflow\BulkCaseResult;
use App\Actions\Workflow\BulkConfirmCases;
use App\Actions\Workflow\BulkOperationResult;
use App\Actions\Workflow\CaseOperationGuard;
use App\Actions\Workflow\RouteEdgeInput;
use App\Actions\Workflow\RouteNodeInput;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\BulkApproval;
use App\Queries\Workflow\CaseOperationDecider;
use Illuminate\Support\Facades\DB;

/**
 * 一括承認・一括確認のテストの手助け(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.7 節)。画面と同じく、行の組
 * (行の bulkApproval = {@see CaseOperationDecider::bulkApprovalsOf()})を写して送る。判定の日 = 操作の基準日。
 *
 * @phpstan-type ScreenRow array{id: int, revision: int, node_id: int, principal_user_id: int|null}
 */
trait RunsBulkOperations
{
    use BuildsRouteVersions;
    use ReadsCaseNotices;

    /**
     * 行の組(画面の can.bulkApprove が偽なら null)。
     */
    protected function bulkApprovalOf(WorkflowCase $case, User $user): ?BulkApproval
    {
        return $this->bulkApprovalsOf($user, $case)[$case->id];
    }

    /**
     * 行の組(案件ごと。渡した案件をまとめて 1 回で判定する)。
     *
     * @return array<int, BulkApproval|null> 案件の id → 組
     */
    protected function bulkApprovalsOf(User $user, WorkflowCase ...$cases): array
    {
        $requester = new Requester($user->id, null);
        $decider = app(CaseOperationDecider::class);
        $fresh = WorkflowCase::query()->whereIn('id', array_map(fn (WorkflowCase $case) => $case->id, $cases))->get();
        $situations = $decider->situationsOf($requester, $this->operationDay(), array_values($fresh->all()));

        return $decider->bulkApprovalsOf($requester, $this->operationDay(), array_values($situations));
    }

    /**
     * 処理の時の組({@see CaseOperationGuard::lockForBulkApprove()}。savepoint の中で鍵を取って巻き戻す)。
     */
    protected function processingApprovalOf(WorkflowCase $case, User $user): ?BulkApproval
    {
        DB::beginTransaction();

        try {
            return app(CaseOperationGuard::class)->lockForBulkApprove($this->contextOf($user), $case->id)[1];
        } finally {
            DB::rollBack();
        }
    }

    /**
     * 画面が送る 1 行(今の改訂番号と行の組)。組が無ければテストを失敗させる。
     *
     * @return ScreenRow
     */
    protected function screenRowOf(WorkflowCase $case, User $user): array
    {
        $approval = $this->bulkApprovalOf($case, $user);
        $this->assertNotNull($approval, "案件 {$case->id} は {$user->code} の一括承認で選べない");

        return $this->rowFor($case, $approval->nodeId, $approval->principalUserId);
    }

    /**
     * 今の改訂番号で、渡した組の 1 行。
     *
     * @return ScreenRow
     */
    protected function rowFor(WorkflowCase $case, int $nodeId, ?int $principalUserId = null): array
    {
        return [
            'id' => $case->id,
            'revision' => $this->revisionOf($case),
            'node_id' => $nodeId,
            'principal_user_id' => $principalUserId,
        ];
    }

    protected function bulkApprove(?User $user, mixed $cases, ?string $comment = null): BulkOperationResult
    {
        return app(BulkApproveCases::class)->execute($this->contextOf($user), $cases, $comment);
    }

    protected function bulkConfirm(?User $user, mixed $caseIds): BulkOperationResult
    {
        return app(BulkConfirmCases::class)->execute($this->contextOf($user), $caseIds);
    }

    /**
     * 結果の行を [案件の id, 結果, 対応, 申請名を持つか] に。
     *
     * @return list<array{int, string, string|null, bool}>
     */
    protected function resultLines(BulkOperationResult $result): array
    {
        return array_map(
            fn (BulkCaseResult $row) => [$row->caseId, $row->outcome->value, $row->guidance, $row->name !== null],
            $result->rows,
        );
    }

    /**
     * 申請 → first(u0002。確認する人 = u0010 = 経理部の参照者)→ second(u0003。申請へ差戻せる)。
     *
     * @return array{list<RouteNodeInput>, list<RouteEdgeInput>}
     */
    protected function confirmingRoute(): array
    {
        $first = new RouteNodeInput(
            'first',
            'first',
            WorkflowNodeKind::Approve,
            WorkflowTargetKind::Fixed,
            [$this->userCondition('u0002')],
            ['ja' => 'first'],
            sendBackCodes: ['apply'],
            confirmSubjectGroupId: $this->confirmGroup('u0010'),
        );
        $second = $this->approveNode('second', 'u0003', ['apply']);

        return [[$this->applyNode(), $first, $second], $this->chain(['apply', 'first'], ['first', 'second'])];
    }
}
