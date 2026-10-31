import type { Graph } from '@dagrejs/dagre';
import { MarkerType } from '@xyflow/react';
import type { Edge } from '@xyflow/react';
import type {
    DiagramInputNode,
    RouteEdgeData,
} from '@/lib/Workflow/diagramTypes';
import type { WorkflowDiagramEdge } from '@/types';

/**
 * フロー図の辺(docs の 設計/ワークフロー/ルートの深さ/画面/図と意匠とE2E.md 8 節): 辺の名前・通らなかった枝の点線・dagre の通り道を直角に折る道。
 */

/** 折れ目を箱の上下から離す距離 */
const BEND_OFFSET = 20;
/** 辺の名前の 1 文字の幅の目安(dagre に名前の場所を空けさせる) */
export const LABEL_CHAR_WIDTH = 13;
const UNTAKEN_TEXT = '通らなかった枝';

export function edgeText(edge: WorkflowDiagramEdge): string | null {
    if (edge.taken === false) {
        return edge.label === null
            ? UNTAKEN_TEXT
            : `${edge.label}(${UNTAKEN_TEXT})`;
    }

    return edge.label;
}

/**
 * 点線の辺(画面/図と意匠とE2E.md 8 節の通らなかった枝): taken が偽の辺と、元のノードが省略で元に入る辺がすべて点線の辺。元から先へ、変わらなくなるまで伝える
 * (辺の並びに頼らない。分岐終了は通過なので、その先は実線に戻る)。
 */
export function dashedEdges(
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

export type Box = { x: number; y: number; width: number; height: number };

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

export function routeEdge(
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
