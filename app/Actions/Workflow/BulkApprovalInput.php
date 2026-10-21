<?php

namespace App\Actions\Workflow;

use App\Queries\Workflow\BulkApproval;

/**
 * 一括承認の入力 cases の 1 行(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.7 節)。画面が行の bulkApproval から写した組
 * (ノードと代理元)を持ち、処理の時に今の状態の組と照合する(画面が確かめた組と違うステップ・代理元で承認しないため)。
 */
final readonly class BulkApprovalInput
{
    /**
     * @param  int  $caseId  案件の id
     * @param  int  $revision  画面を開いた時の改訂番号
     * @param  int  $nodeId  承認するノード(行の組のノード)
     * @param  int|null  $principalUserId  行の組の代理元(null = 自分として)
     */
    public function __construct(
        public int $caseId,
        public int $revision,
        public int $nodeId,
        public ?int $principalUserId,
    ) {}

    /**
     * 画面が確かめた時のまま(改訂番号と組が今の値と同じ)か。
     */
    public function isCurrent(int $revision, ?BulkApproval $approval): bool
    {
        return $approval !== null && $revision === $this->revision
            && $approval->nodeId === $this->nodeId && $approval->principalUserId === $this->principalUserId;
    }
}
