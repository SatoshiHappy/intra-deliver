import type { WorkflowCaseShowProps } from '@/types';

/**
 * 見出しの横の「・<現在のステップ>」(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 1 節)。
 * 未完了だけ出す(完了は札だけ)。並列のステップの名前はサーバーが「、」でつないでいる。出さないときは null。
 */
export function headingStepOf(
    workflowCase: Pick<
        WorkflowCaseShowProps['case'],
        'state' | 'currentSummary'
    >,
): string | null {
    return workflowCase.state === 'active' &&
        workflowCase.currentSummary !== null
        ? `・${workflowCase.currentSummary.name}`
        : null;
}
