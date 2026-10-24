<?php

namespace App\Actions\Workflow;

use App\Actions\Records\Api\DeleteRecordUnchecked;
use App\Enums\Workflow\WorkflowOperation;
use App\Models\Workflow\WorkflowCase;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 案件の削除(案件操作。画面の語は申請の削除。手順と結果は 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.7 節、判定の順は同じ文書の 15.1 節 =
 * {@see CaseManagerGate})。申請書のレコードは判定しない削除の口 {@see DeleteRecordUnchecked} で消す。
 * 一番外のトランザクションでデッドロックをやり直すので、ほかのトランザクションの中から呼ばない
 * (設計/ワークフロー/データモデル/直列化と検査.md の 3.2 節)。
 */
class DeleteCase
{
    /** 試行の回数(負けた側は 2 回目で相手の後に走る。待ちの輪の検出は 1 回ごとに deadlock_timeout かかるので増やさない) */
    private const ATTEMPTS = 3;

    public function __construct(private CaseManagerGate $gate, private DeleteRecordUnchecked $deleteRecord) {}

    /**
     * @param  int  $revision  画面を開いた時の改訂番号
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できないとき
     * @throws AuthorizationException 案件のフローの operate を許されないとき
     * @throws ValidationException 改訂番号・表(case)の誤り、申請書のレコードがほかの行から参照されているとき(record。案件の削除も巻き戻る)
     * @throws DeadlockException デッドロックのやり直しが尽きたとき(口の中の失敗は入れ子の層を通ってこの形で届く)と、ほかのトランザクションの中から呼ばれたとき
     * @throws QueryException 一番外の層(判定の鍵・案件の行の削除)の DB の誤り。デッドロックはやり直しが尽きたとき
     * @throws LogicException 案件がレコードを指していないとき(一時保存は判定で拒まれるので起きない)
     */
    public function execute(AccessContext $context, int $caseId, int $revision): void
    {
        DB::transaction(function () use ($context, $caseId, $revision): void {
            $case = $this->gate->lockAndPermit(WorkflowOperation::DeleteCase, $context, $caseId, $revision)->case();
            $recordId = $case->record_id ?? throw new LogicException("一時保存でない案件 {$case->id} がレコードを指していない");

            // 口は持ち主のあるレコードを拒むので、案件の行を先に消して持ち主を外す
            $case->delete();
            $this->deleteRecord->execute($recordId);
        }, self::ATTEMPTS);
    }
}
