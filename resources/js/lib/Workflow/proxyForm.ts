import type { PickedUser } from '@/lib/Workflow/caseActionForm';
import type { WorkflowProxyFlow, WorkflowProxyKind } from '@/types';

/**
 * 代理を追加するダイアログの入力の形と、送る内容(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.3 節・7.4 節。
 * 送り先の入力 = 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.9 節)。
 */

/** 対象の切替(すべてのフロー / フローを指定) */
export type ProxyScope = 'all' | 'flow';

/**
 * ダイアログの入力。principal = 代理元(代理の管理だけが使う)、proxy = 代理先(どちらも 0 人か 1 人)、
 * flow_code = scope が flow のときの選んだフロー、end_date = 最後の有効な日(空 = 終了日なし)。
 */
export type ProxyForm = {
    principal: PickedUser[];
    proxy: PickedUser[];
    kind: WorkflowProxyKind;
    scope: ProxyScope;
    flow_code: string;
    start_date: string;
    end_date: string;
};

/** 送る内容(principal_user_id は代理の管理だけ) */
export type ProxyPayload = {
    principal_user_id?: number | null;
    proxy_user_id: number | null;
    kind: WorkflowProxyKind;
    workflow_flow_code: string | null;
    start_date: string;
    end_date: string | null;
};

/**
 * ダイアログを開いた時の入力の初期値(種類 = 承認、対象 = すべてのフロー、開始日 = today、終了日 = 空)。today はサーバーの今日(props)。
 */
export function initialProxyForm(today: string): ProxyForm {
    return {
        principal: [],
        proxy: [],
        kind: 'approve',
        scope: 'all',
        flow_code: '',
        start_date: today,
        end_date: '',
    };
}

/**
 * 対象を切り替えた後の入力(フローを指定するときは先頭のフローを選んでおく = 選ばないまま送ると「すべてのフロー」と同じになるため。すべてのフローに戻すと選びを空にする)。
 */
export function withScope(
    data: ProxyForm,
    scope: ProxyScope,
    flows: WorkflowProxyFlow[],
): ProxyForm {
    return {
        ...data,
        scope,
        flow_code: scope === 'flow' ? (flows[0]?.code ?? '') : '',
    };
}

/**
 * 送る内容(空の終了日は終了日なし、対象がすべてのフローならフローのコードは null)。
 */
export function proxyPayloadOf(
    data: ProxyForm,
    isAdmin: boolean,
): ProxyPayload {
    return {
        ...(isAdmin
            ? { principal_user_id: data.principal[0]?.id ?? null }
            : {}),
        proxy_user_id: data.proxy[0]?.id ?? null,
        kind: data.kind,
        workflow_flow_code: data.scope === 'flow' ? data.flow_code : null,
        start_date: data.start_date,
        end_date: data.end_date === '' ? null : data.end_date,
    };
}

/** 日付の入力の形(YYYY-MM-DD) */
const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

/**
 * 人の候補の基準日にする、入力中の開始日(日付の形でなければ送らない = 口が今日にする)。
 */
export function candidateBaseDate(startDate: string): string | undefined {
    return DATE_PATTERN.test(startDate) ? startDate : undefined;
}
