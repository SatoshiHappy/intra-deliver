<?php

namespace App\Queries\Workflow;

use App\Models\Workflow\WorkflowFlowVersion;
use DateTimeInterface;

/**
 * フローの基準日 d に有効な版(申請に使う版 = docs の 設計/ワークフロー/ルートの深さ/データモデル/定義の表.md 2.1、定義の管理.md 2 節)を引く 1 か所の口。
 * 有効 = ユーザー無効でなく期間が d を含む(共通の規則 5.4。重複を排他制約が止めるので、フローごとに高々 1 つ)。無いときの扱いは呼び手が決める。鍵を取らない。
 */
class EffectiveFlowVersions
{
    /** 申請基準日に有効な版が無いときの文(%s = 日付)。正はここ(業務処理の 422 base_date と申請の画面の知らせが同じ文 = {@see ApplicationNext} の noVersionMessage) */
    public const NO_VERSION_MESSAGE = '申請基準日 %s に申請できる版がありません';

    /**
     * フローごとの d に有効な版(d に有効な版の無いフローは含まない)。
     *
     * @param  list<int>  $flowIds
     * @return array<int, int> フローの id → 版の id
     */
    public function idsOn(array $flowIds, DateTimeInterface $date): array
    {
        if ($flowIds === []) {
            return [];
        }

        return array_map('intval', WorkflowFlowVersion::query()->whereIn('workflow_flow_id', $flowIds)->effectiveOn($date)->pluck('id', 'workflow_flow_id')->all());
    }

    /**
     * フローの d に有効な版の id(無ければ null)。
     */
    public function idOn(int $flowId, DateTimeInterface $date): ?int
    {
        return $this->idsOn([$flowId], $date)[$flowId] ?? null;
    }
}
