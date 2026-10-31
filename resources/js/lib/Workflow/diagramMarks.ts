import type {
    DiagramInputNode,
    JointNodeData,
    LayoutEdge,
    NoopNodeData,
    RoutingTone,
} from '@/lib/Workflow/diagramTypes';
import type { WorkflowDiagramEdge, WorkflowStepState } from '@/types';

/**
 * 図の側で足す箱と札(docs の 設計/ワークフロー/ルートの深さ/画面/図と意匠とE2E.md 8 節。データは持たない): 分岐開始から対の分岐終了へ直接つながる辺の
 * 「何もしない」の箱と、縦配置の枠の下の「同期終了」の札。辺は足した箱・札を通るように付け替える。
 */

/** 図の側で足すノード(React Flow のノードの id・種類・中身) */
export type AddedNode =
    | { id: string; type: 'noop'; data: NoopNodeData }
    | { id: string; type: 'joint'; data: JointNodeData };

/**
 * 申請・承認と展開の行の箱の地(処理済み = 処理済みの札の地 status-settled、承認待ち = 承認待ちの札の地 status-pending と太い緑の縁。
 * どちらも札は残す。ほかは card の地。定義の図は状態を持たないので plain = 図と意匠とE2E.md 8 節)
 */
export function stepBoxToneOf(
    stepState: WorkflowStepState | undefined,
): 'settled' | 'pending' | 'plain' {
    if (stepState?.state === 'done') {
        return 'settled';
    }

    return stepState?.state === 'current' ? 'pending' : 'plain';
}

/** 分岐・同期の印の塗りと縁(通過 = 処理済みの札の状態 done は塗り、省略は破線)。案件の図だけが状態を持つ */
export function routingToneOf(
    stepState: WorkflowStepState | undefined,
): RoutingTone {
    if (stepState?.state === 'done') {
        return 'passed';
    }

    return stepState?.state === 'skipped' ? 'skipped' : 'plain';
}

function jointIdOf(frameId: number): string {
    return `${frameId}-joint`;
}

/**
 * 上の段のノードと辺から、足す箱・札と、配置に渡す辺(点線は元の辺の点線を引き継ぐ)を作る。
 */
export function addDiagramMarks(
    roots: DiagramInputNode[],
    edges: WorkflowDiagramEdge[],
    dashed: Set<WorkflowDiagramEdge>,
): { added: AddedNode[]; edges: LayoutEdge[] } {
    const kindOf = new Map(roots.map((node) => [node.id, node.kind]));
    const frames = roots.filter((node) => node.kind === 'parallel');
    const added: AddedNode[] = frames.map((frame) => ({
        id: jointIdOf(frame.id),
        type: 'joint',
        data: { label: '同期終了', tone: routingToneOf(frame.stepState) },
    }));
    // 枠から札へ(枠から出る辺がすべて点線なら点線 = 省いた枠の続き)
    const routed: LayoutEdge[] = frames.map((frame) => {
        const outs = edges.filter((edge) => edge.from === frame.id);

        return {
            ...plainEdge(String(frame.id), jointIdOf(frame.id)),
            dashed: outs.length > 0 && outs.every((edge) => dashed.has(edge)),
        };
    });

    for (const edge of edges) {
        const through = throughMarks(edge, kindOf, dashed.has(edge));
        added.push(...through.added);
        routed.push(...through.edges);
    }

    return { added, edges: routed };
}

/**
 * 1 本の辺を、足した札(縦配置の枠から出る辺は札から出る)と「何もしない」の箱を通る辺にする。
 */
function throughMarks(
    edge: WorkflowDiagramEdge,
    kindOf: Map<number, DiagramInputNode['kind']>,
    dashed: boolean,
): { added: AddedNode[]; edges: LayoutEdge[] } {
    const from =
        kindOf.get(edge.from) === 'parallel'
            ? jointIdOf(edge.from)
            : String(edge.from);
    const to = String(edge.to);
    const { label, conditionText } = edge;
    const first = { from, to, label, conditionText, dashed };

    if (
        kindOf.get(edge.from) !== 'branch_start' ||
        kindOf.get(edge.to) !== 'branch_end'
    ) {
        return { added: [], edges: [first] };
    }

    const noop = `${edge.from}-${edge.to}-noop`;

    return {
        added: [{ id: noop, type: 'noop', data: { taken: edge.taken } }],
        edges: [
            { ...first, to: noop },
            { ...plainEdge(noop, to), dashed },
        ],
    };
}

/** 名前・条件の無い実線の辺 */
export function plainEdge(from: string, to: string): LayoutEdge {
    return { from, to, label: null, conditionText: null, dashed: false };
}
