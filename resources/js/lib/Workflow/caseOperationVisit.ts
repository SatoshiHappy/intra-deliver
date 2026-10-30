import { router } from '@inertiajs/react';
import { caseOperationsUrl } from '@/lib/Workflow/caseOperationList';
import type { WorkflowCaseOperationQuery } from '@/types';

/** 絞り込み・ページを変えたときに取り直す props */
const CASE_OPERATION_PROPS = ['query', 'cases'];

/**
 * 申請操作の一覧を、絞り込み・ページを変えた URL へ部分の再読み込みで移る(URL に残すので、再読み込み・戻る操作で同じ一覧になる。
 * 絞り込みを変えたらページは 1 に戻す)。replace = 履歴を積まない(検索の語の入力の停止ごとの再読み込み)。
 */
export function visitCaseOperations(
    query: WorkflowCaseOperationQuery,
    page = 1,
    replace = false,
): void {
    router.get(
        caseOperationsUrl(query, page),
        {},
        {
            only: CASE_OPERATION_PROPS,
            preserveState: true,
            preserveScroll: true,
            replace,
        },
    );
}
