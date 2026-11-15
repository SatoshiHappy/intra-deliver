import type { WorkflowCaseOperationPanel } from '@/types';

/**
 * 申請の詳細の下に固定した帯に何を出すか(設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 1 節)。
 * operate = 左端の「申請操作」のメニューに渡す申請操作の props(無ければ null)、process = 右寄せの処理のボタン。どちらも無ければ帯を出さない(null)。
 */
export type CaseBarParts = {
    operate: WorkflowCaseOperationPanel | null;
    process: boolean;
};

/**
 * hasProcess = 処理のボタンが 1 つ以上ある(processButtonsOf)、operation = props の operation(申請操作の権限が無ければ null)。
 */
export function caseBarOf(
    hasProcess: boolean,
    operation: WorkflowCaseOperationPanel | null,
): CaseBarParts | null {
    return hasProcess || operation !== null
        ? { operate: operation, process: hasProcess }
        : null;
}
