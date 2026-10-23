import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { WorkflowBulkResult } from '@/types';

/**
 * 一括の結果のダイアログの状態(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 3 節: 全部が成功しなかったとき、一覧を描き直した後に開く)。
 * 結果はフラッシュ bulkResult(応答ごとの値)。閉じたら、その結果では開き直さない(次の一括の結果は別の値なので開く)。
 */
export function useBulkResult() {
    const { flash } = usePage();
    const result = (flash.bulkResult as WorkflowBulkResult | undefined) ?? null;
    const [closed, setClosed] = useState<WorkflowBulkResult | null>(null);

    return {
        result,
        isOpen: result !== null && result !== closed,
        close: () => setClosed(result),
    };
}
