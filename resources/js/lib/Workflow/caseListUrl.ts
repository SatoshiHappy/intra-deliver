import { router } from '@inertiajs/react';
import { SCOPED_TABS } from '@/lib/Workflow/caseList';
import { index } from '@/routes/workflow/cases';
import type { WorkflowCaseScope, WorkflowCaseTab } from '@/types';

/** タブ・切替・ページ・検索の語を変えたときに取り直す props(docs の 設計/ワークフロー/画面/申請と案件.md 4 節) */
export const CASE_LIST_PROPS = ['query', 'counts', 'cases'];

/** 一覧のクエリ(読み取りが解釈した値) */
export type CaseListQuery = {
    tab: WorkflowCaseTab;
    scope: WorkflowCaseScope;
    q: string;
};

/**
 * 一覧の URL(既定の値は付けない。タブの既定 = 未処理、切替の既定 = 未完了(切替を持つタブだけ)、ページの既定 = 1、検索の語の既定 = 空)。
 */
export function caseListUrl(query: CaseListQuery, page = 1): string {
    const q = query.q.trim();

    return index.url({
        query: {
            tab: query.tab === 'pending' ? undefined : query.tab,
            scope:
                SCOPED_TABS.includes(query.tab) && query.scope !== 'active'
                    ? query.scope
                    : undefined,
            page: page === 1 ? undefined : page,
            q: q === '' ? undefined : q,
        },
    });
}

/**
 * 一覧を、クエリを変えた URL へ部分の再読み込みをする(URL に残すので、再読み込み・戻る操作で同じ一覧になる)。
 * replace = 履歴を積まない(検索の語の入力の停止ごとの再読み込み)。
 */
export function visitCaseList(url: string, replace = false): void {
    router.get(
        url,
        {},
        { only: CASE_LIST_PROPS, preserveState: true, replace },
    );
}
