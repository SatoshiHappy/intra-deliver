import type {
    WorkflowCaseAssignNode,
    WorkflowCaseMoveNode,
    WorkflowCaseOperationPanel,
    WorkflowCaseReexpandNode,
    WorkflowCaseRetargetNode,
    WorkflowCaseShowProps,
} from '@/types';

/**
 * 申請操作の単体テストが使う面の props の部品(テストごとに違う値は overrides で渡す)。
 * 名前が `.testing.ts` のファイルは `*.test.ts` からだけ import できる(deps:check が止める)。
 */

type NodeAction = WorkflowCaseShowProps['nodeActions'][number];

export const takahashi = { id: 3, code: 'u0003', name: '高橋 誠' };
export const kobayashi = { id: 5, code: 'u0005', name: '小林 直樹' };

export function retargetNode(
    overrides: Partial<WorkflowCaseRetargetNode> = {},
): WorkflowCaseRetargetNode {
    return {
        nodeId: 11,
        name: '部長',
        single: false,
        max: 20,
        onHold: false,
        targets: [takahashi],
        ...overrides,
    };
}

export function reexpandNode(
    overrides: Partial<WorkflowCaseReexpandNode> = {},
): WorkflowCaseReexpandNode {
    return {
        nodeId: 11,
        name: '部長',
        onHold: false,
        targets: [takahashi],
        expanded: [takahashi],
        summary: null,
        ...overrides,
    };
}

export function moveNode(
    overrides: Partial<WorkflowCaseMoveNode> = {},
): WorkflowCaseMoveNode {
    return {
        nodeId: 11,
        name: '部長',
        onHold: false,
        canForward: true,
        backNodes: [
            { nodeId: 1, name: '申請' },
            { nodeId: 2, name: '課長' },
        ],
        defaultBackNodeId: 2,
        ...overrides,
    };
}

export function assignNode(
    overrides: Partial<WorkflowCaseAssignNode> = {},
): WorkflowCaseAssignNode {
    return {
        nodeId: 31,
        name: '関係部署の合議',
        kindLabel: '縦配置',
        min: 0,
        max: 3,
        ordered: false,
        assigned: [takahashi, kobayashi],
        ...overrides,
    };
}

export function panel(
    overrides: Partial<WorkflowCaseOperationPanel> = {},
): WorkflowCaseOperationPanel {
    return {
        caseName: '新製品の発売計画',
        buttons: {
            retarget: true,
            reexpand: true,
            move: true,
            assign: false,
            release: false,
            destroy: true,
        },
        retargetNodes: [retargetNode()],
        reexpandNodes: [reexpandNode()],
        moveNodes: [moveNode()],
        assignNodes: [],
        releaseNodes: [],
        ...overrides,
    };
}

export function nodeAction(overrides: Partial<NodeAction> = {}): NodeAction {
    return {
        nodeId: 21,
        name: '課長',
        approve: false,
        approveEarly: false,
        reject: false,
        sendBack: false,
        hold: false,
        release: false,
        transfer: false,
        blockedReason: null,
        holdBlockedReason: null,
        sendBackTargets: [],
        sendBackRestricted: false,
        isCouncilVote: false,
        designations: [],
        principals: {},
        ...overrides,
    };
}

/** ダイアログの対象を作るのに要る値(改訂番号 4、コメント 4000 文字) */
export function source(operation: WorkflowCaseOperationPanel = panel()) {
    return { caseId: 7, revision: 4, commentMaxLength: 4000, operation };
}
