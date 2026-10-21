<?php

namespace App\Queries\Workflow;

/**
 * 一括承認で承認する組(ノード, 処理者)(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.7 節)。行の bulkApproval と、
 * 一括承認の処理の時に送られた組と比べる値。作るのは {@see BulkApprovals} だけ。
 */
final readonly class BulkApproval
{
    /**
     * @param  int  $nodeId  承認するノード(案件のノードの id)
     * @param  string  $nodeName  ノードの表示名(展開の行は「{親の名前} {子の名前}」)
     * @param  int|null  $principalUserId  代理元として承認するときの代理元(自分として承認するなら null)
     */
    public function __construct(
        public int $nodeId,
        public string $nodeName,
        public ?int $principalUserId,
    ) {}
}
