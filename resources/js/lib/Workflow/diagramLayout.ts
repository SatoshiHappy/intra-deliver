import { Graph, layout } from '@dagrejs/dagre';
import { MarkerType } from '@xyflow/react';
import type { Edge, Node } from '@xyflow/react';
import type {
    WorkflowAction,
    WorkflowDiagramEdge,
    WorkflowDiagramNode,
    WorkflowStepState,
} from '@/types';

/**
 * フロー図の配置(docs の 設計/ワークフロー/ルートの深さ/画面.md 8 節): 上から下へ、dagre の段組みで分岐・同期の枝を横に並べる。横配置・縦配置は枠(React Flow の
 * 親ノード)の中に子を並べ(横配置は縦一列、縦配置は横一列)、dagre には枠を 1 つの箱として渡す。最後の後に「終了」の印を足す。辺は dagre の通り道を直角に折って描く。
 */

/** 図に渡すノード(案件の図は状態の札の語・処理の種別・指定の前の枠かを持つ) */
export type DiagramInputNode = WorkflowDiagramNode & {
    stepState?: WorkflowStepState;
    processed?: { action: WorkflowAction } | null;
    awaitingDesignation?: boolean;
};

export type StepNodeData = { node: DiagramInputNode };
export type FrameNodeData = { node: DiagramInputNode; childCount: number };
export type RouteEdgeData = {
    path: string;
    labelX: number;
    labelY: number;
    text: string | null; // 辺の名前(通らなかった枝は語を足す)
    dashed: boolean; // 通らなかった枝とその続き(点線)
};

type DiagramNode =
    | Node<StepNodeData, 'step'>
    | Node<StepNodeData, 'routing'>
    | Node<FrameNodeData, 'frame'>
    | Node<Record<string, never>, 'end'>;

/** 図の外周の余白(px)。dagre の margin と、全体が入る表示の padding(WorkflowDiagram)に同じ値を使い、面の高さ = 配置の高さで 1 倍に収める */
export const DIAGRAM_MARGIN = 16;

/** 箱の大きさ(px)。文字の行数が決まっているので固定にする */
const STEP_SIZE = { width: 208, height: 84 };
// 子の箱は札を人の行の下に置くので、上の段の箱より 1 行分高い
const CHILD_SIZE = { width: 176, height: 96 };
const ROUTING_SIZE = { width: 168, height: 52 };
const END_SIZE = { width: 80, height: 30 };
/** 枠の見出しの高さ(px。種類と名前・状態の札の行と、短い文の行の 2 行) */
export const FRAME_HEADER_HEIGHT = 60;
const FRAME = {
    padding: 16,
    header: FRAME_HEADER_HEIGHT,
    gap: 16,
    consensus: 44,
    minWidth: 288,
    emptyHeight: 32,
};
const END_ID = 'end';
/** 折れ目を箱の上下から離す距離 */
const BEND_OFFSET = 20;
/** 辺の名前の 1 文字の幅の目安(dagre に名前の場所を空けさせる) */
const LABEL_CHAR_WIDTH = 13;
const UNTAKEN_TEXT = '通らなかった枝';

/**
 * 図の形の種類の集まり(サーバーの列挙 App\Enums\Workflow\WorkflowNodeKind の写し)。tests/Architecture/WorkflowDiagramMirrorTest.php がサーバーの値と結ぶ
 * (片方だけ変わると失敗)。ROUTING_KINDS = routing()(分岐・同期の箱)、FRAME_KINDS = expanding()(子を持つ枠 = 横配置・縦配置)。
 */
const ROUTING_KINDS = new Set<DiagramInputNode['kind']>([
    'branch_start',
    'branch_end',
    'sync_start',
    'sync_end',
]);
const FRAME_KINDS = new Set<DiagramInputNode['kind']>(['serial', 'parallel']);

function isFrame(node: DiagramInputNode): boolean {
    return FRAME_KINDS.has(node.kind);
}

/** 枠の大きさ(子の数と並べ方で決まる) */
function frameSize(node: DiagramInputNode, childCount: number) {
    const row = node.kind === 'parallel';
    const childrenWidth = row
        ? childCount * CHILD_SIZE.width +
          Math.max(childCount - 1, 0) * FRAME.gap
        : CHILD_SIZE.width;
    const childrenHeight =
        childCount === 0
            ? FRAME.emptyHeight
            : row
              ? CHILD_SIZE.height
              : childCount * CHILD_SIZE.height + (childCount - 1) * FRAME.gap;

    return {
        width: Math.max(childrenWidth + FRAME.padding * 2, FRAME.minWidth),
        height:
            FRAME.header +
            childrenHeight +
            (node.consensus === null ? 0 : FRAME.consensus) +
            FRAME.padding,
    };
}

/** 箱の大きさ(dagre は渡した値の入れ物に座標を書き込むので、いつも新しい入れ物で返す) */
function sizeOf(node: DiagramInputNode, childCount: number) {
    if (isFrame(node)) {
        return frameSize(node, childCount);
    }

    return { ...(ROUTING_KINDS.has(node.kind) ? ROUTING_SIZE : STEP_SIZE) };
}

function edgeText(edge: WorkflowDiagramEdge): string | null {
    if (edge.taken === false) {
        return edge.label === null
            ? UNTAKEN_TEXT
            : `${edge.label}(${UNTAKEN_TEXT})`;
    }

    return edge.label;
}

/**
 * 点線の辺(画面.md 8 節の通らなかった枝): taken が偽の辺と、元のノードが省略で元に入る辺がすべて点線の辺。元から先へ、変わらなくなるまで伝える
 * (辺の並びに頼らない。分岐終了は通過なので、その先は実線に戻る)。
 */
function dashedEdges(
    nodes: DiagramInputNode[],
    edges: WorkflowDiagramEdge[],
): Set<WorkflowDiagramEdge> {
    const skipped = new Set(
        nodes.filter((node) => node.state === 'skipped').map((node) => node.id),
    );
    const dashed = new Set(edges.filter((edge) => edge.taken === false));
    let changed = true;

    while (changed) {
        changed = false;

        for (const edge of edges) {
            const incoming = edges.filter((other) => other.to === edge.from);

            if (
                !dashed.has(edge) &&
                skipped.has(edge.from) &&
                incoming.length > 0 &&
                incoming.every((other) => dashed.has(other))
            ) {
                dashed.add(edge);
                changed = true;
            }
        }
    }

    return dashed;
}

type Box = { x: number; y: number; width: number; height: number };

/**
 * 出る箱の下辺の中央から入る箱の上辺の中央へ、直角に折れる道(途中の dagre の点があれば、その x を通る)。
 */
function routeOf(
    source: Box,
    target: Box,
    middleX: number | null,
): Pick<RouteEdgeData, 'path' | 'labelX' | 'labelY'> {
    const sy = source.y + source.height / 2;
    const ty = target.y - target.height / 2;

    if (middleX === null) {
        const bend = (sy + ty) / 2;

        return {
            path: `M ${source.x} ${sy} V ${bend} H ${target.x} V ${ty}`,
            labelX: (source.x + target.x) / 2,
            labelY: bend,
        };
    }

    const top = sy + BEND_OFFSET;
    const bottom = ty - BEND_OFFSET;

    return {
        path: `M ${source.x} ${sy} V ${top} H ${middleX} V ${bottom} H ${target.x} V ${ty}`,
        labelX: middleX,
        labelY: (top + bottom) / 2,
    };
}

/**
 * 図のノードと辺から、React Flow のノード(親が子より前)と辺、図全体の大きさを作る。
 */
export function layoutDiagram(
    nodes: DiagramInputNode[],
    edges: WorkflowDiagramEdge[],
): {
    nodes: DiagramNode[];
    edges: Edge<RouteEdgeData, 'route'>[];
    width: number;
    height: number;
} {
    const children = new Map<number, DiagramInputNode[]>();

    for (const node of nodes) {
        if (node.parentId !== null) {
            children.set(node.parentId, [
                ...(children.get(node.parentId) ?? []),
                node,
            ]);
        }
    }

    const roots = nodes.filter((node) => node.parentId === null);
    const graph = new Graph();
    graph.setGraph({
        rankdir: 'TB',
        nodesep: 40,
        ranksep: 40,
        marginx: DIAGRAM_MARGIN,
        marginy: DIAGRAM_MARGIN,
    });
    graph.setDefaultEdgeLabel(() => ({}));

    for (const node of roots) {
        graph.setNode(
            String(node.id),
            sizeOf(node, children.get(node.id)?.length ?? 0),
        );
    }

    graph.setNode(END_ID, { ...END_SIZE });

    for (const edge of edges) {
        const text = edgeText(edge);
        graph.setEdge(String(edge.from), String(edge.to), {
            width: text === null ? 0 : text.length * LABEL_CHAR_WIDTH + 16,
            height: text === null ? 0 : 24,
            labelpos: 'c',
        });
    }

    const outgoing = new Set(edges.map((edge) => edge.from));
    const lasts = roots.filter((node) => !outgoing.has(node.id));

    for (const node of lasts) {
        graph.setEdge(String(node.id), END_ID);
    }

    layout(graph);

    const boxOf = (id: string): Box => {
        const placed = graph.node(id);

        return {
            x: placed.x ?? 0,
            y: placed.y ?? 0,
            width: placed.width,
            height: placed.height,
        };
    };

    const dashed = dashedEdges(nodes, edges);

    return {
        nodes: placeNodes(roots, children, boxOf),
        edges: [
            ...edges.map((edge) =>
                routeEdge(
                    String(edge.from),
                    String(edge.to),
                    edgeText(edge),
                    dashed.has(edge),
                    graph,
                    boxOf,
                ),
            ),
            ...lasts.map((node) =>
                routeEdge(String(node.id), END_ID, null, false, graph, boxOf),
            ),
        ],
        width: graph.graph().width ?? 0,
        height: graph.graph().height ?? 0,
    };
}

/**
 * 上の段の箱(左上の座標)と、枠の子(枠の左上からの相対の座標)。
 */
function placeNodes(
    roots: DiagramInputNode[],
    children: Map<number, DiagramInputNode[]>,
    boxOf: (id: string) => Box,
): DiagramNode[] {
    const placed: DiagramNode[] = [];
    const childNodes: DiagramNode[] = [];

    for (const node of roots) {
        const box = boxOf(String(node.id));
        const position = {
            x: box.x - box.width / 2,
            y: box.y - box.height / 2,
        };
        const base = {
            id: String(node.id),
            position,
            width: box.width,
            height: box.height,
        };

        if (isFrame(node)) {
            const kids = children.get(node.id) ?? [];
            placed.push({
                ...base,
                type: 'frame',
                data: { node, childCount: kids.length },
            });
            childNodes.push(...placeChildren(node, kids, box.width));
        } else {
            placed.push({
                ...base,
                type: ROUTING_KINDS.has(node.kind) ? 'routing' : 'step',
                data: { node },
            });
        }
    }

    const end = boxOf(END_ID);
    placed.push({
        id: END_ID,
        type: 'end',
        position: { x: end.x - end.width / 2, y: end.y - end.height / 2 },
        width: end.width,
        height: end.height,
        data: {},
    });

    return [...placed, ...childNodes];
}

/**
 * 枠の子(枠の左上からの相対の座標)。子の並び全体を枠の幅の中央に置く(上下の辺が子の並びの中央を通る)。
 */
function placeChildren(
    parent: DiagramInputNode,
    kids: DiagramInputNode[],
    frameWidth: number,
): DiagramNode[] {
    const row = parent.kind === 'parallel';
    const rowWidth = row
        ? kids.length * CHILD_SIZE.width +
          Math.max(kids.length - 1, 0) * FRAME.gap
        : CHILD_SIZE.width;
    const left = (frameWidth - rowWidth) / 2;

    return kids.map((child, index) => ({
        id: String(child.id),
        type: 'step',
        parentId: String(parent.id),
        extent: 'parent',
        position: row
            ? {
                  x: left + index * (CHILD_SIZE.width + FRAME.gap),
                  y: FRAME.header,
              }
            : {
                  x: left,
                  y: FRAME.header + index * (CHILD_SIZE.height + FRAME.gap),
              },
        width: CHILD_SIZE.width,
        height: CHILD_SIZE.height,
        data: { node: child },
    }));
}

function routeEdge(
    from: string,
    to: string,
    text: string | null,
    dashed: boolean,
    graph: Graph,
    boxOf: (id: string) => Box,
): Edge<RouteEdgeData, 'route'> {
    const points = graph.edge(from, to)?.points ?? [];
    const middle = points.slice(1, -1);
    const middleX =
        middle.length === 0 ? null : middle[Math.floor(middle.length / 2)].x;

    return {
        id: `${from}-${to}`,
        source: from,
        target: to,
        type: 'route',
        markerEnd: { type: MarkerType.ArrowClosed, width: 16, height: 16 },
        data: {
            ...routeOf(boxOf(from), boxOf(to), middleX),
            text,
            dashed,
        },
    };
}
