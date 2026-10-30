import type { WorkflowCaseRow, WorkflowPerson } from './workflowRows';

/**
 * 申請操作の画面の型(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.1 節・7.2 節。App\Queries\Workflow の
 * CaseOperationList・CaseOperationPanel の形)。
 */

/** 案件の詳細の案件に 1 つの操作の可否(状態 × 役割 × 入口の 403 の判定。operate = 申請操作の面のボタンがどれか真) */
export type WorkflowCaseCan = {
    pullBack: boolean;
    reapply: boolean;
    withdraw: boolean;
    confirm: boolean; // 自分が確認対象者の開いている確認がある(確認の面の主ボタン)
    operate: boolean;
};

/** 申請操作の面のボタン 6 つの可否 */
type WorkflowCaseOperationButtons = {
    retarget: boolean;
    reexpand: boolean;
    move: boolean;
    assign: boolean;
    release: boolean;
    destroy: boolean;
};

/** 処理担当者の変更の対象のステップ(表が許す現在のステップ。single = 展開の行 = 1 人だけ、max = 変更後の人数の上限) */
export type WorkflowCaseRetargetNode = {
    nodeId: number;
    name: string;
    single: boolean;
    max: number;
    onHold: boolean;
    targets: WorkflowPerson[];
};

/** 再展開の対象のステップ(expanded = 申請基準日で展開した人。0 人なら空 = 「決定」を押せない) */
export type WorkflowCaseReexpandNode = {
    nodeId: number;
    name: string;
    onHold: boolean;
    targets: WorkflowPerson[];
    expanded: WorkflowPerson[];
    summary: string | null; // 「?」の {要約}。null なら「?」を出さない
};

/** ステップの移動の対象のステップ(canForward が偽なら「進む」を押せない。defaultBackNodeId = 既定の戻り先) */
export type WorkflowCaseMoveNode = {
    nodeId: number;
    name: string;
    onHold: boolean;
    canForward: boolean;
    backNodes: { nodeId: number; name: string }[];
    defaultBackNodeId: number | null;
};

/** 指定を変更できるステップ(ordered = 並びが処理の順) */
export type WorkflowCaseAssignNode = {
    nodeId: number;
    name: string;
    kindLabel: string;
    min: number;
    max: number;
    ordered: boolean;
    assigned: WorkflowPerson[];
};

/** 詳細の props operation(申請操作の面。ボタンがどれも偽なら null) */
export type WorkflowCaseOperationPanel = {
    caseName: string;
    buttons: WorkflowCaseOperationButtons;
    retargetNodes: WorkflowCaseRetargetNode[];
    reexpandNodes: WorkflowCaseReexpandNode[];
    moveNodes: WorkflowCaseMoveNode[];
    assignNodes: WorkflowCaseAssignNode[];
    releaseNodes: { nodeId: number; name: string }[]; // 保留解除で開くステップ(保留中の承認のステップ。buttons.release が偽なら空)
};

/** 申請操作の一覧の絞り込み(読み取りが解釈した後の値) */
export type WorkflowCaseOperationQuery = {
    flow: string | null;
    scope: 'active' | 'completed';
    q: string;
    overdue: boolean;
};

/** ページ Workflow/Admin/CaseOperations の props */
export type WorkflowCaseOperationsProps = {
    query: WorkflowCaseOperationQuery;
    flows: { code: string; name: string }[];
    cases: {
        data: WorkflowCaseRow[];
        currentPage: number;
        lastPage: number;
        total: number;
    };
};
