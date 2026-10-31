import type {
    WorkflowLinkedPerson,
    WorkflowPerson,
    WorkflowPersonLinks,
} from './workflowRows';
import type { WorkflowProxyKind } from './workflowValues';

/**
 * 代理の設定・代理の管理の画面の型(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.3 節・7.4 節。
 * App\Queries\Workflow\ProxyList の形。日付は YYYY-MM-DD、終了日は最後の有効な日)。
 */

/** 人と今日の所属(今日に有効でない人は所属が null) */
export type WorkflowProxyPerson = WorkflowPerson & {
    department: string | null;
};

/** 代理の状態(今日で決める。有効 / 開始前 / 終了) */
export type WorkflowProxyStatus = 'active' | 'upcoming' | 'ended';

/** 代理の行に共通の値(flowName が null = すべてのフロー、endDate が null = 終了日なし) */
type WorkflowProxyBase = {
    id: number;
    kind: WorkflowProxyKind;
    flowName: string | null;
    startDate: string;
    endDate: string | null;
    status: WorkflowProxyStatus;
};

/** 代理の設定の行(person = 自分の代理先の表では代理先、自分の代理元の表では代理元) */
export type WorkflowProxyRow = WorkflowProxyBase & {
    person: WorkflowProxyPerson;
};

/** 代理の管理の行 */
export type WorkflowAdminProxyRow = WorkflowProxyBase & {
    principal: WorkflowProxyPerson;
    proxy: WorkflowProxyPerson;
    registeredBy: WorkflowPerson;
};

/** 代理を追加するダイアログの「対象」の選択肢 */
export type WorkflowProxyFlow = { code: string; name: string };

/** ページ Workflow/Proxies の props */
export type WorkflowProxiesProps = {
    outgoing: WorkflowProxyRow[];
    incoming: WorkflowProxyRow[];
    flows: WorkflowProxyFlow[];
    /** サーバーの今日(YYYY-MM-DD。追加のダイアログの開始日の既定値) */
    today: string;
    links: WorkflowPersonLinks; // 名前の札の「…」のリンク
};

/** 代理の管理の絞り込み(人はクエリの id から引いた結果。解釈できない値は null) */
export type WorkflowAdminProxyQuery = {
    principal: WorkflowLinkedPerson | null;
    proxy: WorkflowLinkedPerson | null;
    kind: WorkflowProxyKind | null;
    active: boolean;
};

/** ページ Workflow/Admin/Proxies の props */
export type WorkflowAdminProxiesProps = {
    query: WorkflowAdminProxyQuery;
    flows: WorkflowProxyFlow[];
    today: string;
    links: WorkflowPersonLinks; // 名前の札の「…」のリンク
    proxies: {
        data: WorkflowAdminProxyRow[];
        currentPage: number;
        lastPage: number;
        total: number;
    };
};
