import type {
    WorkflowAction,
    WorkflowDiagramNode,
    WorkflowStepState,
} from '@/types';

/** 図に渡すノード(案件の図は状態の札の語・処理の種別・指定の前の枠かを持つ) */
export type DiagramInputNode = WorkflowDiagramNode & {
    stepState?: WorkflowStepState;
    processed?: { action: WorkflowAction } | null;
    awaitingDesignation?: boolean;
};

export type StepNodeData = { node: DiagramInputNode };
export type FrameNodeData = { node: DiagramInputNode; childCount: number };
/** 定義の図の枠の中の仮の札(まだ指定していない処理対象者の席) */
export type PlaceholderNodeData = { label: string; optional: boolean };
export type RouteEdgeData = {
    path: string;
    labelX: number;
    labelY: number;
    text: string | null; // 辺の名前(通らなかった枝は語を足す)
    dashed: boolean; // 通らなかった枝とその続き(点線)
};
