<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowBulkAttempt;
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
            $attempt = $this->approveOne($context, $row, $comment);

            if ($attempt !== WorkflowBulkAttempt::Approved) {
                [$stoppedId, $isStale] = [$row->caseId, $attempt === WorkflowBulkAttempt::StoppedStale];
                break;
            }

            $approvedIds[] = $row->caseId;
        }

        $caseIds = array_map(fn (BulkApprovalInput $row) => $row->caseId, $rows);
        $results = $this->results->approvals($context, $caseIds, $approvedIds, $stoppedId, $isStale);

        return new BulkOperationResult(BulkOperationResult::APPROVE, $results);
    }

    /**
     * 1 件を承認する。止まった案件のトランザクションは巻き戻る(組の違いは何も書いていない)。
     *
     * @throws LogicException 案件でないモデルが見つからないとき(内部の誤り)
     */
    private function approveOne(AccessContext $context, BulkApprovalInput $row, ?string $comment): WorkflowBulkAttempt
    {
        try {
            return DB::transaction(function () use ($context, $row, $comment): WorkflowBulkAttempt {
                // 組の出し直しと単独の承認の判定を通った結果で承認する
                $permit = $this->guard->lockAndPermitBulkApproval($context, $row);

                if ($permit === null) {
                    return WorkflowBulkAttempt::StoppedStale;
                }

                $this->approve->approvePermitted($context, $permit, $comment);

                return WorkflowBulkAttempt::Approved;
            });
        } catch (ModelNotFoundException|ValidationException $exception) {
            // 閲覧できない・無い案件と、単独の承認が拒んだ案件で止める(結果の分け方は今の状態から作る)
            self::throwIfInternal($exception);

            return WorkflowBulkAttempt::StoppedRefused;
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
