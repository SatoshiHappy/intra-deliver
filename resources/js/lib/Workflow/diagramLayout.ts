import { Graph, layout } from '@dagrejs/dagre';
import type { Edge, Node } from '@xyflow/react';
import { labelSize, routeEdges } from '@/lib/Workflow/diagramEdges';
import type { Box, Shape } from '@/lib/Workflow/diagramEdges';
import { dashedEdges } from '@/lib/Workflow/diagramEdges';
import { addDiagramMarks, plainEdge } from '@/lib/Workflow/diagramMarks';
import type { AddedNode } from '@/lib/Workflow/diagramMarks';
import {
    frameSize,
    placeChildren,
    slotsOf,
} from '@/lib/Workflow/diagramFrames';
import type { ChildNode } from '@/lib/Workflow/diagramFrames';
import type {
    DiagramInputNode,
    FrameNodeData,
    JointNodeData,
    LayoutEdge,
    NoopNodeData,
    RouteEdgeData,
    StepNodeData,
} from '@/lib/Workflow/diagramTypes';
import type { WorkflowDiagramEdge } from '@/types';

/**
 * フロー図の配置(docs の 設計/ワークフロー/ルートの深さ/画面/図と意匠とE2E.md 8 節): 上から下へ、dagre の段組みで分岐・同期の枝を横に並べる。横配置・縦配置は枠(React Flow の
 * 親ノード)の中に子を並べ(横配置は縦一列、縦配置は横一列)、dagre には枠を 1 つの箱として渡す。最後の後に「終了」の印を足す。辺は dagre の通り道を直角に折って描く。
 * 定義の図の枠は、子の代わりに仮の札(placeholders)を同じ並べ方で置く。図の側で足す箱と札(「何もしない」「同期終了」)は diagramMarks.ts。
 */

type DiagramNode =
    | Node<StepNodeData, 'step'>
    | Node<StepNodeData, 'routing'>
    | Node<FrameNodeData, 'frame'>
    | ChildNode
    | Node<NoopNodeData, 'noop'>
    | Node<JointNodeData, 'joint'>
    | Node<Record<string, never>, 'end'>;

/** 図の外周の余白(px)。dagre の margin と、全体が入る表示の padding(WorkflowDiagram)に同じ値を使い、面の高さ = 配置の高さで 1 倍に収める */
export const DIAGRAM_MARGIN = 16;

/** 箱の大きさ(px)。文字の行数が決まっているので固定にする */
const STEP_SIZE = { width: 208, height: 84 };
/** 分岐(横長のひし形)と同期(長円の札。図の側で足す「同期終了」も) */
const DIAMOND_SIZE = { width: 168, height: 56 };
const PILL_SIZE = { width: 120, height: 32 };
const END_SIZE = { width: 80, height: 30 };
const END_ID = 'end';

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

/** 分岐開始・分岐終了 = ひし形(辺が左右の角から出入りする) */
function isDiamond(node: DiagramInputNode): boolean {
    return node.kind === 'branch_start' || node.kind === 'branch_end';
}

/** 箱の大きさ(dagre は渡した値の入れ物に座標を書き込むので、いつも新しい入れ物で返す) */
function sizeOf(node: DiagramInputNode, childCount: number) {
    if (isFrame(node)) {
        return frameSize(
            node.kind === 'parallel',
            node.consensus !== null,
            childCount,
        );
    }

    if (ROUTING_KINDS.has(node.kind)) {
        return { ...(isDiamond(node) ? DIAMOND_SIZE : PILL_SIZE) };
    }

    return { ...STEP_SIZE };
}

/** 図の側で足す箱(「何もしない」は同じ段のステップの箱と同じ大きさ)と札の大きさ */
function addedSizeOf(node: AddedNode) {
    return { ...(node.type === 'noop' ? STEP_SIZE : PILL_SIZE) };
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
    const children = childrenOf(nodes);
    const roots = nodes.filter((node) => node.parentId === null);
    const marks = addDiagramMarks(roots, edges, dashedEdges(nodes, edges));
    const sizes = new Map([
        ...roots.map(
            (node) => [String(node.id), rootSize(node, children)] as const,
        ),
        ...marks.added.map((node) => [node.id, addedSizeOf(node)] as const),
    ]);
    const outgoing = new Set(marks.edges.map((edge) => edge.from));
    const routed = [
        ...marks.edges,
        ...[...sizes.keys()]
            .filter((id) => !outgoing.has(id))
            .map((id) => plainEdge(id, END_ID)),
    ];
    const graph = layoutGraph(sizes, routed);
    const boxOf = (id: string): Box => {
        const { x, y, width, height } = graph.node(id);

        return { x: x ?? 0, y: y ?? 0, width, height };
    };
    const diamonds = new Set(roots.filter(isDiamond).map((n) => String(n.id)));
    const shapeOf = (id: string): Shape =>
        diamonds.has(id) ? 'diamond' : 'box';
    const added = marks.added.map((node) => ({
        ...node,
        ...placedBox(boxOf(node.id)),
    }));

    return {
        nodes: [...placeNodes(roots, children, boxOf), ...added],
        edges: routeEdges(routed, graph, boxOf, shapeOf),
        width: graph.graph().width ?? 0,
        height: graph.graph().height ?? 0,
    };
}

/** 枠の id → 子(展開の行) */
function childrenOf(
    nodes: DiagramInputNode[],
): Map<number, DiagramInputNode[]> {
    const children = new Map<number, DiagramInputNode[]>();

    for (const node of nodes) {
        if (node.parentId !== null) {
            const siblings = children.get(node.parentId) ?? [];
            children.set(node.parentId, [...siblings, node]);
        }
    }

    return children;
}

/** 上の段の箱の大きさ(枠は子と仮の札の数で決まる) */
function rootSize(
    node: DiagramInputNode,
    children: Map<number, DiagramInputNode[]>,
) {
    return sizeOf(node, slotsOf(node, children.get(node.id) ?? []).length);
}

/**
 * dagre の段組み(箱と終了の印、札の場所を空けた辺)。
 */
function layoutGraph(
    sizes: Map<string, { width: number; height: number }>,
    edges: LayoutEdge[],
): Graph {
    const graph = new Graph();
    graph.setGraph({
        rankdir: 'TB',
        nodesep: 40,
        ranksep: 40,
        marginx: DIAGRAM_MARGIN,
        marginy: DIAGRAM_MARGIN,
    });
    graph.setDefaultEdgeLabel(() => ({}));

    for (const [id, size] of sizes) {
        graph.setNode(id, size);
    }

    graph.setNode(END_ID, { ...END_SIZE });

    for (const edge of edges) {
        graph.setEdge(edge.from, edge.to, {
            ...labelSize(edge),
            labelpos: 'c',
        });
    }

    // 同じ箱から出る枝は、辺の並び(分岐の並び順)のとおり左から右へ置く
    layout(graph, { constraints: orderConstraints(edges) });

    return graph;
}

/** 同じ箱から出る辺の行き先の左右(隣り合う 2 つずつ。dagre の並べ替えの制約) */
function orderConstraints(
    edges: LayoutEdge[],
): { left: string; right: string }[] {
    return edges.flatMap((edge, index) => {
        const next = edges
            .slice(index + 1)
            .find((other) => other.from === edge.from);

        return next === undefined ? [] : [{ left: edge.to, right: next.to }];
    });
}

/** 中心の座標の箱から、React Flow の左上の座標と大きさ */
function placedBox(box: Box) {
    return {
        position: { x: box.x - box.width / 2, y: box.y - box.height / 2 },
        width: box.width,
        height: box.height,
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
        const base = { id: String(node.id), ...placedBox(box) };

        if (isFrame(node)) {
            const slots = slotsOf(node, children.get(node.id) ?? []);
            const data = { node, childCount: slots.length };
            placed.push({ ...base, type: 'frame', data });
            childNodes.push(...placeChildren(node, slots, box.width));
        } else {
            const type = ROUTING_KINDS.has(node.kind) ? 'routing' : 'step';
            placed.push({ ...base, type, data: { node } });
        }
    }

    const end = { id: END_ID, ...placedBox(boxOf(END_ID)) };
    placed.push({ ...end, type: 'end', data: {} });

    return [...placed, ...childNodes];
}
