import type { Graph } from '@dagrejs/dagre';
import { MarkerType } from '@xyflow/react';
import type { Edge } from '@xyflow/react';
import type {
    DiagramInputNode,
    LayoutEdge,
    RouteEdgeData,
} from '@/lib/Workflow/diagramTypes';
import type { WorkflowDiagramEdge } from '@/types';

/**
 * フロー図の辺(docs の 設計/ワークフロー/ルートの深さ/画面/図と意匠とE2E.md 8 節): 通らなかった枝の点線・直角に折る道・ひし形の左右の角・枝の札の置き場。
 */

/** 折れ目を箱の上下から離す距離 */
const BEND_OFFSET = 20;
/** 札の 1 文字の幅の目安(上の行 = 辺の名前、下の行 = 条件の文。dagre に札の場所を空けさせる) */
const NAME_CHAR_WIDTH = 13;
const CONDITION_CHAR_WIDTH = 11;
/** 札の高さ(2 行)と、行き先の箱との間 */
const LABEL_HEIGHT = 36;
const LABEL_GAP = 8;

/** 箱の形(ひし形 = 分岐開始・分岐終了。辺が左右の角から出入りする) */
export type Shape = 'box' | 'diamond';
type Side = 'left' | 'right' | 'middle';
export type Box = { x: number; y: number; width: number; height: number };

/** dagre に空けさせる札の大きさ */
export function labelSize(edge: LayoutEdge): { width: number; height: number } {
    if (edge.label === null) {
        return { width: 0, height: 0 };
    }

    const width = Math.max(
        edge.label.length * NAME_CHAR_WIDTH,
        (edge.conditionText ?? '').length * CONDITION_CHAR_WIDTH,
    );

    return { width: width + 16, height: LABEL_HEIGHT };
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

/**
 * ひし形の 1 つの側の辺の並びに角を割り当てる(並びの x の順で、外側の 2 本だけが左右の角。角の外に無い辺と間の辺は上下の角)。
 */
function sidesOf(
    group: LayoutEdge[],
    xOf: (edge: LayoutEdge) => number,
    box: Box,
    sides: Map<LayoutEdge, Side>,
): void {
    const sorted = group.toSorted((a, b) => xOf(a) - xOf(b));
    const first = sorted[0];
    const last = sorted.at(-1);

    if (first !== undefined && xOf(first) < box.x - box.width / 2) {
        sides.set(first, 'left');
    }

    if (
        last !== undefined &&
        last !== first &&
        xOf(last) > box.x + box.width / 2
    ) {
        sides.set(last, 'right');
    }
}

/** 角の x(左右の角 = ひし形の左右の端、上下の角 = 中央) */
function cornerX(box: Box, side: Side): number {
    const half = side === 'left' ? -1 : side === 'right' ? 1 : 0;

    return box.x + (half * box.width) / 2;
}

/**
 * 出る箱から入る箱への直角の道。ひし形の左右の角から出る辺は横へ出て行き先の真上で下りる。左右の角へ入る辺は真下へ下りて横から入る。
 * 名前のある辺は出た直後に折れる(札は行き先の箱のすぐ上)。ほかは途中の dagre の点があれば、その x を通る。
 */
function pathOf(
    source: Box,
    target: Box,
    out: Side,
    into: Side,
    bend: { labelled: boolean; middleX: number | null },
): string {
    const sy = source.y + source.height / 2;
    const ty = target.y - target.height / 2;

    if (out !== 'middle') {
        return `M ${cornerX(source, out)} ${source.y} H ${target.x} V ${ty}`;
    }

    if (into !== 'middle') {
        return `M ${source.x} ${sy} V ${target.y} H ${cornerX(target, into)}`;
    }

    if (bend.labelled || bend.middleX === null) {
        const y = bend.labelled ? sy + BEND_OFFSET : (sy + ty) / 2;

        return `M ${source.x} ${sy} V ${y} H ${target.x} V ${ty}`;
    }

    return `M ${source.x} ${sy} V ${sy + BEND_OFFSET} H ${bend.middleX} V ${ty - BEND_OFFSET} H ${target.x} V ${ty}`;
}

/**
 * ひし形の角を使う辺(出る辺は左右の角、入る辺は左右の角。ほかの辺は上下の角 = middle)。
 */
function cornerSides(
    edges: LayoutEdge[],
    boxOf: (id: string) => Box,
    shapeOf: (id: string) => Shape,
): { outs: Map<LayoutEdge, Side>; ins: Map<LayoutEdge, Side> } {
    const outs = new Map<LayoutEdge, Side>();
    const ins = new Map<LayoutEdge, Side>();

    for (const id of new Set(edges.flatMap((edge) => [edge.from, edge.to]))) {
        if (shapeOf(id) === 'diamond') {
            const from = edges.filter((edge) => edge.from === id);
            const into = edges.filter((edge) => edge.to === id);
            sidesOf(from, (edge) => boxOf(edge.to).x, boxOf(id), outs);
            sidesOf(into, (edge) => boxOf(edge.from).x, boxOf(id), ins);
        }
    }

    return { outs, ins };
}

/** 札の中央の高さ(出る箱ごと。名前のある辺の行き先の上辺のうち、いちばん高いものの上) */
function labelCenters(
    edges: LayoutEdge[],
    boxOf: (id: string) => Box,
): Map<string, number> {
    const tops = new Map<string, number>();

    for (const edge of edges.filter((one) => one.label !== null)) {
        const target = boxOf(edge.to);
        const top = target.y - target.height / 2;
        tops.set(edge.from, Math.min(tops.get(edge.from) ?? top, top));
    }

    return new Map(
        [...tops].map(([id, top]) => [id, top - LABEL_GAP - LABEL_HEIGHT / 2]),
    );
}

/**
 * 配置に渡した辺の道と札。札は行き先の箱のすぐ上で、同じ箱から出る枝の札は同じ高さにそろえる。
 */
export function routeEdges(
    edges: LayoutEdge[],
    graph: Graph,
    boxOf: (id: string) => Box,
    shapeOf: (id: string) => Shape,
): Edge<RouteEdgeData, 'route'>[] {
    const sides = cornerSides(edges, boxOf, shapeOf);
    const centers = labelCenters(edges, boxOf);

    return edges.map((edge) => {
        const points = graph.edge(edge.from, edge.to)?.points ?? [];
        const middle = points.slice(1, -1);
        const middleX =
            middle.length === 0
                ? null
                : middle[Math.floor(middle.length / 2)].x;
        const path = pathOf(
            boxOf(edge.from),
            boxOf(edge.to),
            sides.outs.get(edge) ?? 'middle',
            sides.ins.get(edge) ?? 'middle',
            { labelled: edge.label !== null, middleX },
        );

        return {
            id: `${edge.from}-${edge.to}`,
            source: edge.from,
            target: edge.to,
            type: 'route',
            markerEnd: { type: MarkerType.ArrowClosed, width: 16, height: 16 },
            data: {
                path,
                labelX: boxOf(edge.to).x,
                labelY: centers.get(edge.from) ?? 0,
                text: edge.label,
                conditionText: edge.conditionText,
                dashed: edge.dashed,
            },
        };
    });
}
