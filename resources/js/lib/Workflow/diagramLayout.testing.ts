import { layoutDiagram } from './diagramLayout';
import type { DiagramInputNode } from './diagramTypes';
import type { WorkflowDiagramEdge } from '@/types';

/** 図の配置のテストの支え(ノード・辺の組み立てと、配置の結果の箱と辺の読み出し) */

export function node(
    id: number,
    kind: DiagramInputNode['kind'],
    overrides: Partial<DiagramInputNode> = {},
): DiagramInputNode {
    return {
        id,
        code: `n${id}`,
        name: `ノード${id}`,
        kind,
        parentId: null,
        sequence: null,
        subtitle: null,
        state: null,
        people: [],
        placeholders: [],
        consensus: null,
        ...overrides,
    };
}

export function edge(
    from: number,
    to: number,
    overrides: Partial<WorkflowDiagramEdge> = {},
): WorkflowDiagramEdge {
    return {
        from,
        to,
        label: null,
        conditionText: null,
        taken: null,
        ...overrides,
    };
}

export const PLACEHOLDERS = [
    { label: '承認 1', optional: false },
    { label: '承認 2', optional: false },
    { label: '承認 3〜5', optional: true },
];
/** 版 3 と同じ形(申請 → 分岐 → 部長 / ステップの無い枝 → 分岐終了 → 縦配置 → 責任者) */
export function branchRoute(taken: {
    manager: boolean | null;
    direct: boolean | null;
}) {
    const nodes = [
        node(1, 'apply'),
        node(2, 'branch_start', { name: '予算' }),
        node(3, 'approve', { name: '部長' }),
        node(4, 'branch_end', { name: '分岐終了' }),
        node(5, 'parallel', { placeholders: PLACEHOLDERS }),
        node(6, 'approve'),
    ];
    const edges = [
        edge(1, 2),
        edge(2, 3, {
            label: '予算 300 万円以上',
            conditionText: '予算(万円) ≧ 300',
            taken: taken.manager,
        }),
        edge(2, 4, {
            label: '予算 300 万円未満',
            conditionText: '予算(万円) < 300',
            taken: taken.direct,
        }),
        edge(3, 4),
        edge(4, 5),
        edge(5, 6),
    ];

    return layoutDiagram(nodes, edges);
}

export function boxOf(layout: ReturnType<typeof layoutDiagram>, id: string) {
    const found = layout.nodes.find((one) => one.id === id);

    if (found === undefined) {
        throw new Error(`箱 ${id} が無い`);
    }

    return {
        type: found.type,
        data: found.data,
        x: found.position.x,
        y: found.position.y,
        width: found.width ?? 0,
        height: found.height ?? 0,
    };
}

export function edgeOf(layout: ReturnType<typeof layoutDiagram>, id: string) {
    const found = layout.edges.find((one) => one.id === id)?.data;

    if (found === undefined) {
        throw new Error(`辺 ${id} が無い`);
    }

    return found;
}
