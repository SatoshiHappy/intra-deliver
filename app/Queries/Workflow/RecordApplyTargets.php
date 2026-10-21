<?php

namespace App\Queries\Workflow;

use App\Queries\Records\Api\RecordDisplayQuery;

/**
 * 既存のデータからの申請の申請先のレコードの読み方(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請.md の 3 節・4 節の 0):
 * 404 の段で見る業務モジュール(親の無いレコードだけ)と、持ち主の有無。入口の口と申請の業務処理が同じ読み方を使う。
 * 判定をしない・鍵を取らない(競合は申請の行の一意と外部キー、更新の口の持ち主の確かめが止める = 同じ文書の 5 節)。
 */
class RecordApplyTargets
{
    public function __construct(
        private RecordDisplayQuery $display,
        private WorkflowRecordHolds $holds,
    ) {}

    /**
     * 親の無いレコードの業務モジュールのコード。無いレコード・明細の行は null。
     */
    public function moduleCodeOf(int $recordId): ?string
    {
        if ($this->display->topLevelRecordIdOf($recordId) !== $recordId) {
            return null;
        }

        return $this->display->topLevelModuleCodeOf($recordId);
    }

    /**
     * 持ち主(未完了の案件)があるか。
     */
    public function isHeld(int $recordId): bool
    {
        return $this->holds->holdsOf([$recordId]) !== [];
    }
}
