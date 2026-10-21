<?php

namespace App\Actions\Workflow;

use App\Models\Workflow\WorkflowCase;
use App\Support\AccessContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 一括承認(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.7 節)。1 件ずつ単独の承認({@see ApproveCase})を通し、判定・書き込み・
 * 通知を単独の承認と同じにする。トランザクションは案件ごとなので、止まった案件より前は確定する。
 */
class BulkApproveCases
{
    public function __construct(
        private BulkInputValidator $validator,
        private CaseInputRules $inputRules,
        private CaseOperationGuard $guard,
        private ApproveCase $approve,
        private BulkResultRows $results,
    ) {}

    /**
     * @param  mixed  $cases  入力 cases(各行 = id・revision・node_id・principal_user_id。入口から受けたまま)
     *
     * @throws ValidationException 選択(cases)・コメント(comment)の誤り。どの案件も処理する前に投げる
     */
    public function execute(AccessContext $context, mixed $cases, ?string $comment): BulkOperationResult
    {
        $rows = $this->validator->approvals($cases);
        $this->inputRules->comment($comment);
        $approvedIds = [];
        [$stoppedId, $isStale] = [null, false];

        foreach ($rows as $row) {
            $stop = $this->approveOne($context, $row, $comment);

            if ($stop !== null) {
                [$stoppedId, $isStale] = [$row->caseId, $stop];
                break;
            }

            $approvedIds[] = $row->caseId;
        }

        $caseIds = array_map(fn (BulkApprovalInput $row) => $row->caseId, $rows);
        $results = $this->results->approvals($context, $caseIds, $approvedIds, $stoppedId, $isStale);

        return new BulkOperationResult(BulkOperationResult::APPROVE, $results);
    }

    /**
     * 1 件を承認する。止まったら、その理由が画面の古さ(改訂番号か組の違い)かを返す(承認できたら null)。
     * 止まった案件のトランザクションは巻き戻る(組の違いは何も書いていない)。
     *
     * @throws LogicException 案件でないモデルが見つからないとき(内部の誤り)
     */
    private function approveOne(AccessContext $context, BulkApprovalInput $row, ?string $comment): ?bool
    {
        try {
            return DB::transaction(function () use ($context, $row, $comment): ?bool {
                [$case, $approval] = $this->guard->lockForBulkApprove($context, $row->caseId);

                if ($approval === null || ! $row->isCurrent($case->revision, $approval)) {
                    return true;
                }

                [$nodeId, $principal] = [$approval->nodeId, $approval->principalUserId];
                $this->approve->execute($context, $case->id, $row->revision, $nodeId, $comment, [], $principal);

                return null;
            });
        } catch (ModelNotFoundException|ValidationException $exception) {
            // 閲覧できない・無い案件と、単独の承認が拒んだ案件で止める(結果の分け方は今の状態から作る)
            self::throwIfInternal($exception);

            return false;
        }
    }

    /**
     * 止めてよい 404 は案件のものだけ。ほかのモデルの 404 はそのまま投げると報告されない 404 の応答に化けるので、内部の誤りに包む。
     *
     * @param  ModelNotFoundException<Model>|ValidationException  $exception
     *
     * @throws LogicException
     */
    private static function throwIfInternal(ModelNotFoundException|ValidationException $exception): void
    {
        if ($exception instanceof ModelNotFoundException && $exception->getModel() !== WorkflowCase::class) {
            throw new LogicException('一括承認の中で案件でないモデルが見つからない', previous: $exception);
        }
    }
}
