<?php

namespace App\Actions\Workflow;

use App\Models\Workflow\WorkflowCase;
use App\Support\AccessContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 一括確認(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.7 節)。並びの順に 1 件ずつ、単独の確認({@see ConfirmCase}。
 * 1 件ごとに 1 つのトランザクション)を呼び、失敗した案件で止める(前は確定・後は処理しない)。確認は通知を送らない。
 */
class BulkConfirmCases
{
    public function __construct(
        private BulkInputValidator $validator,
        private ConfirmCase $confirm,
        private BulkResultRows $results,
    ) {}

    /**
     * @param  mixed  $caseIds  入力 case_ids(入口から受けたまま)
     *
     * @throws ValidationException 選択の誤り(case_ids)。どの案件も処理する前に投げる
     * @throws LogicException 案件でないモデルが見つからないとき(内部の誤り)
     */
    public function execute(AccessContext $context, mixed $caseIds): BulkOperationResult
    {
        $ids = $this->validator->caseIds($caseIds);
        $confirmedIds = [];

        foreach ($ids as $caseId) {
            try {
                $this->confirm->execute($context, $caseId);
            } catch (ModelNotFoundException|ValidationException $exception) {
                // 閲覧できない・無い案件と、確認できる確認が無い案件で止める(結果の分け方は今の状態から作る)
                self::throwIfInternal($exception);
                break;
            }

            $confirmedIds[] = $caseId;
        }

        $results = $this->results->confirmations($context, $ids, $confirmedIds);

        return new BulkOperationResult(BulkOperationResult::CONFIRM, $results);
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
            throw new LogicException('一括確認の中で案件でないモデルが見つからない', previous: $exception);
        }
    }
}
