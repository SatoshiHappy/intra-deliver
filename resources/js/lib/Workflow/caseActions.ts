import type {
    WorkflowCasePullBackNode,
    WorkflowCaseShowProps,
    WorkflowDesignation,
    WorkflowNodeOperation,
    WorkflowPrincipal,
    WorkflowPullBackNode,
} from '@/types';

/**
 * 処理のダイアログの対象と、見出しの右のボタンの並び(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 4 節・5 節)。
 */

/** ダイアログで行う処理(ステップごとの 7 つと、案件に 1 つの引戻し・取止め。再申請は再申請の画面へのリンク、確認は押すと送る) */
export type CaseOperation = WorkflowNodeOperation | 'pullBack' | 'withdraw';

/** ダイアログの文言の表の 1 行(callout = 注意の囲み。見出しと文) */
type OperationText = {
    trigger: string;
    title: string;
    note: string | null;
    callout: { title: string; text: string } | null;
    isDestructive: boolean;
};

/** 処理ごとの開くボタン・題・説明・注意の囲み・決定のボタンの見た目(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 5 節) */
export const OPERATION_TEXTS: Record<CaseOperation, OperationText> = {
    approve: {
        trigger: '承認',
        title: '承認する',
        note: null,
        callout: null,
        isDestructive: false,
    },
    approveEarly: {
        trigger: '承認終了',
        title: '承認を終了する',
        note: '後のステップへ進まずに申請を完了します。',
        callout: null,
        isDestructive: false,
    },
    reject: {
        trigger: '否認',
        title: '否認する',
        note: '申請を否認して完了します。この操作は取り消せません。',
        callout: null,
        isDestructive: true,
    },
    sendBack: {
        trigger: '差戻し',
        title: '差戻しする',
        note: null,
        callout: null,
        isDestructive: false,
    },
    hold: {
        trigger: '保留',
        title: '保留する',
        note: null,
        callout: {
            title: 'このステップの処理を保留します',
            text: '保留中は、保留した人とその代理関係にある人だけが処理できます。期限と経過日数は、保留中も数えます。',
        },
        isDestructive: false,
    },
    release: {
        trigger: '保留解除',
        title: '保留を解除する',
        note: '保留を解除すると、承認待ちに戻ります。処理担当者の全員が、再び処理できます。',
        callout: null,
        isDestructive: false,
    },
    transfer: {
        trigger: '担当変更',
        title: '担当変更する',
        note: null,
        callout: {
            title: '担当変更後は取り消せません',
            text: 'この申請は、自分の未処理から外れ、変更後の処理担当者の未処理に表示されます。変更後の処理担当者へ通知が届きます。',
        },
        isDestructive: false,
    },
    pullBack: {
        trigger: '引戻し',
        title: '引戻す',
        note: '次のステップの処理担当者が処理する前に、自分のステップへ戻します。',
        callout: null,
        isDestructive: false,
    },
    withdraw: {
        trigger: '取止め',
        title: '取止める',
        note: '申請を取止めて完了します。この操作は取り消せません。',
        callout: null,
        isDestructive: true,
    },
};

/** 申請者承認防止で拒まれる操作と、拒むときの見出し(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 5 節) */
export const GUARD_TITLES: Partial<Record<CaseOperation, string>> = {
    approve: '承認できません',
    approveEarly: '承認できません',
    reject: '承認できません',
    hold: '保留できません',
    pullBack: '引戻しできません',
};

/**
 * ダイアログで選べるステップ 1 つ(ステップごとの操作 = 詳細の nodeActions のうちその操作を許すもの、引戻し = 引戻せるステップ)。
 * blockedReason = 申請者承認防止の文言(あれば「決定」を押せない)、principals = 処理担当者の選択肢(2 つ以上のときだけ欄を出す。
 * 空 = 欄に依らず fixedPrincipalId を送る)、fixedPrincipalId = 引戻しの h の処理者(自分なら null)、sendBackTargets = 差戻し先(写した並び。既定は先頭)、
 * sendBackRestricted = 差戻しの制限の 1 行を出すか、isCouncilVote = 縦配置の子(否認の文)、assigneeFields = 指定するステップ(承認だけ)。
 */
export type CaseActionNode = {
    id: number;
    name: string;
    blockedReason: string | null;
    principals: WorkflowPrincipal[];
    fixedPrincipalId: number | null;
    sendBackTargets: { id: number; name: string }[];
    sendBackRestricted: boolean;
    isCouncilVote: boolean;
    assigneeFields: WorkflowDesignation[];
};

/**
 * 開いた時の対象の写し(開くボタンを押した時の props から取る。開いている間、ダイアログはこれだけを読む)。
 * revision = 画面を開いた時の改訂番号、commentMaxLength = コメントの文字数の上限、nodes = 選べるステップ(node_id で送る。
 * 2 つ以上なら選択の欄。取止めは空 = node_id を送らない)、fixedPrincipalId = 取止めの処理者(申請者。自分なら null)。
 */
export type CaseActionTarget = {
    operation: CaseOperation;
    caseId: number;
    caseName: string;
    revision: number;
    commentMaxLength: number;
    nodes: CaseActionNode[];
    fixedPrincipalId: number | null;
};

type NodeActionRow = WorkflowCaseShowProps['nodeActions'][number];

/** 申請者承認防止の文言を持つ操作 → その文言の項目(差戻し・保留解除・担当変更は拒まない) */
function blockedReasonOf(
    operation: WorkflowNodeOperation,
    row: NodeActionRow,
): string | null {
    if (operation === 'hold') {
        return row.holdBlockedReason;
    }

    return operation === 'approve' ||
        operation === 'approveEarly' ||
        operation === 'reject'
        ? row.blockedReason
        : null;
}

/**
 * ステップごとの操作のダイアログで選べるステップ(その操作を許す nodeActions の行。写した並び)。
 */
export function actionNodesOf(
    operation: WorkflowNodeOperation,
    rows: NodeActionRow[],
): CaseActionNode[] {
    return rows
        .filter((row) => row[operation])
        .map((row) => ({
            id: row.nodeId,
            name: row.name,
            blockedReason: blockedReasonOf(operation, row),
            principals: row.principals[operation] ?? [],
            fixedPrincipalId: null,
            sendBackTargets: row.sendBackTargets,
            sendBackRestricted: row.sendBackRestricted,
            isCouncilVote: row.isCouncilVote,
            assigneeFields: row.designations,
        }));
}

/**
 * 引戻せるステップから、引戻しのダイアログで選べるステップを作る(申請者承認防止の文言は写す。送る処理者は h の処理者 principalUserId で、
 * 一覧の行は持たないので null = 自分。引戻しは差戻し・指定を持たない)。
 */
export function pullBackNodesOf(
    nodes: (WorkflowPullBackNode | WorkflowCasePullBackNode)[],
): CaseActionNode[] {
    return nodes.map((node) => ({
        id: node.id,
        name: node.name,
        blockedReason: node.blockedReason,
        principals: [],
        fixedPrincipalId:
            'principalUserId' in node ? node.principalUserId : null,
        sendBackTargets: [],
        sendBackRestricted: false,
        isCouncilVote: false,
        assigneeFields: [],
    }));
}

/** 詳細のダイアログの対象を作るのに要る props */
type DetailActions = Pick<
    WorkflowCaseShowProps,
    'nodeActions' | 'pullBackNodes' | 'casePrincipal' | 'commentMaxLength'
> & { caseId: number; caseName: string; revision: number };

/**
 * 詳細の見出しの右のボタンが開くダイアログの対象(引戻し = 引戻せるステップ、取止め = ステップなしで申請者を処理者に、ほか = nodeActions)。
 */
export function caseActionTargetOf(
    operation: CaseOperation,
    detail: DetailActions,
): CaseActionTarget {
    const base = {
        operation,
        caseId: detail.caseId,
        caseName: detail.caseName,
        revision: detail.revision,
        commentMaxLength: detail.commentMaxLength,
    };

    if (operation === 'withdraw') {
        return { ...base, nodes: [], fixedPrincipalId: detail.casePrincipal };
    }

    return {
        ...base,
        nodes:
            operation === 'pullBack'
                ? pullBackNodesOf(detail.pullBackNodes)
                : actionNodesOf(operation, detail.nodeActions),
        fixedPrincipalId: null,
    };
}

/** 見出しの右のボタン 1 つ(ダイアログを開く・再申請の画面へのリンク・押すと送る確認) */
export type HeaderButton =
    | { kind: 'dialog'; operation: CaseOperation }
    | { kind: 'reapply' }
    | { kind: 'confirm' };

/** ステップごとに許される操作の並び(見出しの右のボタンの並びの前半。並び = docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 4 節) */
const NODE_OPERATIONS: WorkflowNodeOperation[] = [
    'approve',
    'approveEarly',
    'reject',
    'sendBack',
    'hold',
    'release',
    'transfer',
];

/**
 * 見出しの右のボタンの並び(どれかのステップで許される操作だけ。並び = docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 4 節。
 * 前半は {@link NODE_OPERATIONS})。
 */
export function headerButtonsOf(
    show: Pick<WorkflowCaseShowProps, 'nodeActions' | 'can'>,
): HeaderButton[] {
    const dialog = (operation: CaseOperation): HeaderButton => ({
        kind: 'dialog',
        operation,
    });

    return [
        ...NODE_OPERATIONS.filter((operation) =>
            show.nodeActions.some((row) => row[operation]),
        ).map(dialog),
        ...(show.can.pullBack ? [dialog('pullBack')] : []),
        ...(show.can.reapply ? [{ kind: 'reapply' } as const] : []),
        ...(show.can.withdraw ? [dialog('withdraw')] : []),
        ...(show.can.confirm ? [{ kind: 'confirm' } as const] : []),
    ];
}
