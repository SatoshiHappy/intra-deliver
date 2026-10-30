import { actionNodesOf } from '@/lib/Workflow/caseActions';
import { assignOptionOf } from '@/lib/Workflow/caseOperationTexts';
import type { PickedUser } from '@/lib/Workflow/caseActionForm';
import type {
    CaseActionNode,
    CaseActionTarget,
} from '@/lib/Workflow/caseActions';
import type {
    MoveDirection,
    OperationKind,
} from '@/lib/Workflow/caseOperationTexts';
import type {
    WorkflowCaseAssignNode,
    WorkflowCaseMoveNode,
    WorkflowCaseOperationPanel,
    WorkflowCaseReexpandNode,
    WorkflowCaseRetargetNode,
    WorkflowCaseShowProps,
} from '@/types';

/**
 * 申請操作のダイアログの対象・入力・送る内容と、保留解除の対象(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.2 節)。
 */

/** ダイアログの対象に共通の値(開いた時の写し。開いている間、ダイアログはこれだけを読む) */
type OperationTargetBase = {
    caseId: number;
    caseName: string;
    revision: number;
    commentMaxLength: number;
};

/** 開いたダイアログの対象(種類ごと。選べるステップは面の props の写し) */
export type OperationTarget =
    | (OperationTargetBase & {
          kind: 'retarget';
          nodes: WorkflowCaseRetargetNode[];
      })
    | (OperationTargetBase & {
          kind: 'reexpand';
          nodes: WorkflowCaseReexpandNode[];
      })
    | (OperationTargetBase & { kind: 'move'; nodes: WorkflowCaseMoveNode[] })
    | (OperationTargetBase & {
          kind: 'assign';
          nodes: WorkflowCaseAssignNode[];
      });

/** 面の props から対象を作るのに要る値 */
type OperationSource = {
    caseId: number;
    revision: number;
    commentMaxLength: number;
    operation: WorkflowCaseOperationPanel;
};

/**
 * 種類ごとのダイアログの対象(面の props の選べるステップを写す)。
 */
export function operationTargetOf(
    kind: OperationKind,
    source: OperationSource,
): OperationTarget {
    const { operation } = source;
    const base = {
        caseId: source.caseId,
        caseName: operation.caseName,
        revision: source.revision,
        commentMaxLength: source.commentMaxLength,
    };

    switch (kind) {
        case 'retarget':
            return { ...base, kind, nodes: operation.retargetNodes };
        case 'reexpand':
            return { ...base, kind, nodes: operation.reexpandNodes };
        case 'move':
            return { ...base, kind, nodes: operation.moveNodes };
        case 'assign':
            return { ...base, kind, nodes: operation.assignNodes };
    }
}

/**
 * 再展開の「決定」を押せるか(展開した結果が 1 人以上のときだけ。ほかの拒否は送った時の 422)。
 */
export function canDecideReexpand(
    node: WorkflowCaseReexpandNode | undefined,
): boolean {
    return node !== undefined && node.expanded.length > 0;
}

/** ダイアログの入力(direction・send_back_node_id はステップの移動、users は処理担当者の変更・指定の変更) */
export type OperationForm = {
    node_id: string;
    users: PickedUser[];
    comment: string;
    direction: MoveDirection;
    send_back_node_id: string;
};

/**
 * 選んでいるステップ(id の文字列で引く。無ければ undefined)。
 */
export function nodeIn<Node extends { nodeId: number }>(
    nodes: Node[],
    nodeId: string,
): Node | undefined {
    return nodes.find((candidate) => String(candidate.nodeId) === nodeId);
}

/**
 * 移動の種類のうち選べるもの(戻る = 戻り先の候補がある、進む = 進めるステップ、終了 = いつも)。
 */
export function movableDirectionsOf(
    node: WorkflowCaseMoveNode | undefined,
): Record<MoveDirection, boolean> {
    return {
        back: (node?.backNodes.length ?? 0) > 0,
        forward: node?.canForward === true,
        finish: true,
    };
}

/**
 * 移動の種類の初期値(戻る、戻れなければ進む、進めなければ終了)。
 */
function initialDirectionOf(node: WorkflowCaseMoveNode): MoveDirection {
    const movable = movableDirectionsOf(node);

    return movable.back ? 'back' : movable.forward ? 'forward' : 'finish';
}

/**
 * ステップを選んだときに入れ直す欄(ステップの移動 = 移動の種類と戻り先の既定、指定の変更 = 今の指定、ほか = 変更後の人は空)。
 */
export function nodeDefaultsOf(
    target: OperationTarget,
    nodeId: string,
): Pick<OperationForm, 'users' | 'direction' | 'send_back_node_id'> {
    const empty: Pick<
        OperationForm,
        'users' | 'direction' | 'send_back_node_id'
    > = {
        users: [],
        direction: 'back',
        send_back_node_id: '',
    };

    if (target.kind === 'move') {
        const node = nodeIn(target.nodes, nodeId);

        return node === undefined
            ? empty
            : {
                  ...empty,
                  direction: initialDirectionOf(node),
                  send_back_node_id:
                      node.defaultBackNodeId === null
                          ? ''
                          : String(node.defaultBackNodeId),
              };
    }

    return target.kind === 'assign'
        ? { ...empty, users: nodeIn(target.nodes, nodeId)?.assigned ?? [] }
        : empty;
}

/**
 * 開いた時の入力の初期値(先頭のステップを選んでおく)。
 */
export function initialOperationForm(target: OperationTarget): OperationForm {
    const first = target.nodes[0];
    const nodeId = first === undefined ? '' : String(first.nodeId);

    return { node_id: nodeId, comment: '', ...nodeDefaultsOf(target, nodeId) };
}

/**
 * 「対象のステップ」の選択欄(欄の名前と選択肢。指定の変更は「ステップ」でいつも出し、ほかはステップが 2 つ以上のときだけ。出さないなら null)。
 */
export function nodeSelectOf(
    target: OperationTarget,
): { label: string; choices: { id: number; name: string }[] } | null {
    if (target.kind === 'assign') {
        const choices = target.nodes.map((node) => ({
            id: node.nodeId,
            name: assignOptionOf(node),
        }));

        return { label: 'ステップ', choices };
    }

    const nodes: { nodeId: number; name: string }[] = target.nodes;

    return nodes.length > 1
        ? {
              label: '対象のステップ',
              choices: nodes.map((node) => ({
                  id: node.nodeId,
                  name: node.name,
              })),
          }
        : null;
}

/**
 * 種類ごとの入力(処理担当者の変更・指定の変更 = 人の並び、ステップの移動 = 移動の種類と戻り先。戻り先は戻るだけ)。
 */
function inputOf(data: OperationForm, target: OperationTarget) {
    switch (target.kind) {
        case 'retarget':
        case 'assign':
            return { user_ids: data.users.map((user) => user.id) };
        case 'move':
            return {
                direction: data.direction,
                send_back_node_id:
                    data.direction === 'back'
                        ? Number(data.send_back_node_id)
                        : null,
            };
        case 'reexpand':
            return {};
    }
}

/**
 * 送る内容(再展開は種類ごとの入力を持たない)。
 */
export function operationPayloadOf(
    data: OperationForm,
    target: OperationTarget,
) {
    return {
        revision: target.revision,
        node_id: Number(data.node_id),
        comment: data.comment,
        ...inputOf(data, target),
    };
}

type NodeActionRows = WorkflowCaseShowProps['nodeActions'];

/**
 * 保留解除のダイアログで選べるステップ(面の releaseNodes の並び)。自分の処理対象の行(詳細の nodeActions の release が真)は
 * 処理担当者の選択肢を持つのでその行を使い、ほか(案件の管理者として解除する)は処理者を自分にする。
 */
export function releaseNodesOf(
    operation: WorkflowCaseOperationPanel,
    rows: NodeActionRows,
): CaseActionNode[] {
    const own = new Map(
        actionNodesOf('release', rows).map((node) => [node.id, node]),
    );

    return operation.releaseNodes.map(
        ({ nodeId, name }) =>
            own.get(nodeId) ?? {
                id: nodeId,
                name,
                blockedReason: null,
                principals: [],
                fixedPrincipalId: null,
                sendBackTargets: [],
                sendBackRestricted: false,
                isCouncilVote: false,
                assigneeFields: [],
            },
    );
}

/**
 * 保留解除のダイアログの対象(既存の処理のダイアログが開く。選べるステップが 2 つ以上なら「処理するステップ」の選択が先頭に出る)。
 */
export function releaseTargetOf(
    source: OperationSource & { caseName: string; nodeActions: NodeActionRows },
): CaseActionTarget {
    return {
        operation: 'release',
        caseId: source.caseId,
        caseName: source.caseName,
        revision: source.revision,
        commentMaxLength: source.commentMaxLength,
        nodes: releaseNodesOf(source.operation, source.nodeActions),
        fixedPrincipalId: null,
    };
}
