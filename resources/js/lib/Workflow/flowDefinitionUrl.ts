import { router } from '@inertiajs/react';
import { show } from '@/routes/workflow/admin/flows';
import type { WorkflowFlowDefinitionTab } from '@/types';

/**
 * 詳細の URL と部分の再読み込み(docs の 設計/ワークフロー/ルートの深さ/画面/README.md の 1.1)。version・tab・node は URL に残し、
 * 再読み込み・戻る操作で同じ状態になる。サーバーは解釈した後の値を props で返す。
 */

/** 詳細のクエリ(node = 選んだステップのコード。無ければサーバーが申請のステップにする) */
type FlowShowQuery = {
    version: number;
    tab: WorkflowFlowDefinitionTab;
    node?: string;
};

/**
 * タブごとに取り直す props(ほかの props は今の画面のまま)。
 */
const TAB_PROPS: Record<WorkflowFlowDefinitionTab, string[]> = {
    diagram: ['tab', 'selectedVersion', 'diagram', 'selectedNode', 'options'],
    versions: ['tab', 'versions', 'today'],
    referrers: ['tab', 'referrers'],
};

/**
 * 詳細の URL。
 */
export function flowShowUrl(flow: string, query: FlowShowQuery): string {
    return show.url(flow, {
        query: { version: query.version, tab: query.tab, node: query.node },
    });
}

/**
 * タブを開くときに取り直す props。
 */
export function propsOfTab(tab: WorkflowFlowDefinitionTab): string[] {
    return TAB_PROPS[tab];
}

/** ステップを選び直したときに取り直す props(図は同じ版のまま) */
const STEP_PROPS = ['selectedNode', 'options'];

/**
 * 詳細を、クエリを変えた URL へ部分の再読み込みで移る(版の切替。同じコードのステップが新しい版にあれば選んだまま)。
 */
export function visitFlowShow(flow: string, query: FlowShowQuery): void {
    router.get(
        flowShowUrl(flow, query),
        {},
        {
            only: propsOfTab(query.tab),
            preserveState: true,
            preserveScroll: true,
        },
    );
}

/**
 * 図で別のステップを選ぶ(選んだステップの設定と選択の欄の候補だけを取り直す。URL の node に残る)。
 */
export function visitFlowStep(flow: string, query: FlowShowQuery): void {
    router.get(
        flowShowUrl(flow, query),
        {},
        { only: STEP_PROPS, preserveState: true, preserveScroll: true },
    );
}
