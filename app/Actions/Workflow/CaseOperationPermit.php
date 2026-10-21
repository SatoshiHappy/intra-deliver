<?php

namespace App\Actions\Workflow;

use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Workflow\CaseSituation;
use LogicException;

/**
 * 判定を通った結果({@see CaseOperationGuard::lockAndPermit()} が返す)。呼び手は操作するノードと処理者を導き直さず、これを使う
 * (判定と操作が同じ状況を見るため)。
 */
final readonly class CaseOperationPermit
{
    /**
     * @param  CaseSituation  $situation  判定に使った状況(case = 鍵を取った行)
     * @param  WorkflowCaseNode|null  $node  操作するノード(承認・承認終了・否認・差戻し・引戻し・保留・保留解除・振替は入力の node_id、再申請・取止めは申請のノード。ほかは null)
     * @param  WorkflowFlow|null  $flow  FOR SHARE で鍵を取ったフローの行(申請 = 一時保存からの申請だけ)
     */
    public function __construct(
        public CaseSituation $situation,
        public ?WorkflowCaseNode $node,
        public ?WorkflowFlow $flow,
    ) {}

    public function case(): WorkflowCase
    {
        return $this->situation->case;
    }

    /**
     * 判定を通った処理者 P(自分か、確かめた代理元・一時保存の持ち主。{@see CaseOperationGuard::lockAndPermit()})。
     *
     * @throws LogicException 処理者が無いとき(閲覧の判定を通った利用者がいない = 不変の条件の破れ)
     */
    public function processorUserId(): int
    {
        $caseId = $this->situation->case->id;

        return $this->situation->processorUserId ?? throw new LogicException("案件 {$caseId} の判定に処理者が無い");
    }

    /**
     * 操作するノード(ノードを受ける操作だけが呼ぶ)。
     *
     * @throws LogicException 操作するノードが無いとき(呼び手の誤り)
     */
    public function node(): WorkflowCaseNode
    {
        return $this->node ?? throw new LogicException("案件 {$this->situation->case->id} の操作に操作するノードが無い");
    }

    /**
     * 鍵を取ったフローの行(一時保存からの申請だけが呼ぶ)。
     *
     * @throws LogicException フローの鍵を取っていないとき(呼び手の誤り)
     */
    public function flow(): WorkflowFlow
    {
        return $this->flow ?? throw new LogicException("案件 {$this->situation->case->id} のフローの鍵を取っていない");
    }
}
