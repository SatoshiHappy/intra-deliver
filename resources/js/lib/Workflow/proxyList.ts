import { index } from '@/routes/workflow/admin/proxies';
import type {
    WorkflowAdminProxyQuery,
    WorkflowAdminProxyRow,
    WorkflowPerson,
    WorkflowProxyKind,
    WorkflowProxyPerson,
    WorkflowProxyRow,
    WorkflowProxyStatus,
} from '@/types';

/**
 * 代理の表の行と、代理の管理の絞り込みの URL(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.3 節・7.4 節)。
 */

/** 表の 1 行(people = 人の列の順の人。自分の表 = 1 人、代理の管理 = 代理元・代理先) */
export type ProxyTableRow = {
    id: number;
    people: WorkflowProxyPerson[];
    kind: WorkflowProxyKind;
    flowName: string | null;
    startDate: string;
    endDate: string | null;
    status: WorkflowProxyStatus;
    registeredBy: WorkflowPerson | null;
};

/**
 * 代理の設定の行を表の行にする。
 */
export function ownTableRow(row: WorkflowProxyRow): ProxyTableRow {
    return {
        id: row.id,
        people: [row.person],
        kind: row.kind,
        flowName: row.flowName,
        startDate: row.startDate,
        endDate: row.endDate,
        status: row.status,
        registeredBy: null,
    };
}

/**
 * 代理の管理の行を表の行にする。
 */
export function adminTableRow(row: WorkflowAdminProxyRow): ProxyTableRow {
    return {
        id: row.id,
        people: [row.principal, row.proxy],
        kind: row.kind,
        flowName: row.flowName,
        startDate: row.startDate,
        endDate: row.endDate,
        status: row.status,
        registeredBy: row.registeredBy,
    };
}

/**
 * 代理の管理の一覧の URL(既定の値は付けない。人の既定 = 絞らない、種類の既定 = すべて、有効の既定 = 偽、ページの既定 = 1)。
 */
export function adminProxiesUrl(
    query: WorkflowAdminProxyQuery,
    page = 1,
): string {
    return index.url({
        query: {
            principal: query.principal?.id,
            proxy: query.proxy?.id,
            kind: query.kind ?? undefined,
            active: query.active ? 1 : undefined,
            page: page === 1 ? undefined : page,
        },
    });
}

/**
 * 代理の管理の 0 件の文(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.4 節に文が無いので、7.3 節の
 * 「…はありません」と、申請一覧の「絞り込みがあれば該当する…」の規則に倣った)。
 */
export function adminEmptyTextOf(query: WorkflowAdminProxyQuery): string {
    const filtered =
        query.principal !== null ||
        query.proxy !== null ||
        query.kind !== null ||
        query.active;

    return filtered ? '該当する代理はありません' : '代理はありません';
}
