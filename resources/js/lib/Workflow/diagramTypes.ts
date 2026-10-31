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

/** 申請・承認と展開の行の箱(inCouncil = 並列承認の枠の中の箱。題を人の名前にする) */
export type StepNodeData = { node: DiagramInputNode; inCouncil?: boolean };
export type FrameNodeData = { node: DiagramInputNode; childCount: number };
/** 定義の図の枠の中の仮の札(まだ指定していない処理対象者の席) */
export type PlaceholderNodeData = { label: string; optional: boolean };
/** 図の側で足す「何もしない」の箱(ステップの無い枝。taken = 案件の評価、定義の図は null) */
export type NoopNodeData = { taken: boolean | null };
/** 分岐・同期の印の塗りと縁(通った = 塗り、省略 = 破線、ほかは既定) */
export type RoutingTone = 'plain' | 'passed' | 'skipped';
/** 図の側で足す「同期終了」の札(縦配置の枠の下。塗りと縁は枠の状態から) */
export type JointNodeData = { label: string; tone: RoutingTone };
export type RouteEdgeData = {
    path: string;
    labelX: number;
    labelY: number;
    text: string | null; // 辺の名前(札の上の行)
    conditionText: string | null; // 条件の文(札の下の行)
    dashed: boolean; // 通らなかった枝とその続き(点線)
};

/** 配置に渡す辺(図の側で足した箱・札を通す。id は React Flow のノードの id) */
export type LayoutEdge = {
    from: string;
    to: string;
    label: string | null;
    conditionText: string | null;
    dashed: boolean;
};
