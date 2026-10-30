import { index } from '@/routes/workflow/admin/cases';
import type { WorkflowCaseOperationQuery } from '@/types';

/**
 * 申請操作の一覧の絞り込みの URL と 0 件の文(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.1 節、
 * クエリ = 設計/ワークフロー/案件の運用/認可と一覧.md の 2.3 節)。
 */

/** 0 件の文 */
export const CASE_OPERATION_EMPTY_TEXT = '該当する申請はありません';

/** 状態の切替の選択肢(並びの順) */
export const CASE_OPERATION_SCOPES: {
    scope: WorkflowCaseOperationQuery['scope'];
    label: string;
}[] = [
    { scope: 'active', label: '未完了' },
    { scope: 'completed', label: '完了' },
];

/**
 * 申請操作の一覧の URL(既定の値は付けない。フローの既定 = すべて、状態の既定 = 未完了、期限超過の既定 = 偽、ページの既定 = 1、
 * 検索の語の既定 = 空。完了のときは期限超過を読まないので付けない)。
 */
export function caseOperationsUrl(
    query: WorkflowCaseOperationQuery,
    page = 1,
): string {
    const q = query.q.trim();
    const isCompleted = query.scope === 'completed';

    return index.url({
        query: {
            flow: query.flow ?? undefined,
            scope: isCompleted ? 'completed' : undefined,
            q: q === '' ? undefined : q,
            overdue: query.overdue && !isCompleted ? 1 : undefined,
            page: page === 1 ? undefined : page,
        },
    });
}
