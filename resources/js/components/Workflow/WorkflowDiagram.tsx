import {
    BaseEdge,
    Controls,
    EdgeLabelRenderer,
    Handle,
    Position,
    ReactFlow,
    useReactFlow,
    useStore,
} from '@xyflow/react';
import type {
    Edge,
    EdgeProps,
    FitViewOptions,
    Node,
    NodeProps,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import { useEffect, useMemo } from 'react';
import {
    CaseStepBadge,
    StepStateBadge,
} from '@/components/Workflow/WorkflowStatusBadge';
import { cn } from '@/lib/utils';
import {
    DIAGRAM_MARGIN,
    FRAME_HEADER_HEIGHT,
    layoutDiagram,
} from '@/lib/Workflow/diagramLayout';
import type {
    DiagramInputNode,
    FrameNodeData,
    RouteEdgeData,
    StepNodeData,
} from '@/lib/Workflow/diagramLayout';
import { personName } from '@/lib/Workflow/workflowLabels';
import type { WorkflowDiagramEdge, WorkflowStepState } from '@/types';

/** 図の高さの上限(px。初期データの版 3 の図が 1 倍で入る高さ)。超えたら全体が入るまで縮め(0.6 倍まで)、それでも入らなければ図の中で移動して見る(設計/ワークフロー/ルートの深さ/画面.md 8 節) */
const MAX_HEIGHT = 1120;

/**
 * 全体が入る表示の決まり(最初の表示・面の大きさや配置が変わったとき・「全体を表示」のボタンで同じ値)。拡大は 1 倍まで。padding は配置の外周の余白と同じ px なので、
 * 面の高さ = 配置の高さなら 1 倍で全体が入る。
 */
const FIT_VIEW_OPTIONS: FitViewOptions = {
    maxZoom: 1,
    minZoom: 0.6,
    padding: `${DIAGRAM_MARGIN}px`,
};

/** 配置の種類の語(枠の見出し)。サーバーの App\Queries\Workflow\CaseDesignations::KIND_LABELS の写し(tests/Architecture/WorkflowDiagramMirrorTest.php が結ぶ) */
const FRAME_KIND_LABELS: Partial<Record<DiagramInputNode['kind'], string>> = {
    serial: '横配置',
    parallel: '縦配置',
};

/** 状態ごとの箱の縁(状態色 = 札と同じトークン。到達していないは既定の縁、省略は薄い破線の縁 = 設計/ワークフロー/ルートの深さ/画面.md 8 節) */
const BORDER_CLASSES: Record<WorkflowStepState['state'], string> = {
    done: 'border-status-settled-foreground/70',
    current: 'border-status-pending-foreground',
    returned: 'border-status-returned-foreground',
    upcoming: 'border-border',
    skipped: 'border-dashed border-border text-muted-foreground',
};

/** 拡大・縮小の操作の読み上げの名前(React Flow の既定は英語) */
const ARIA_LABELS = {
    'controls.ariaLabel': '図の表示の操作',
    'controls.zoomIn.ariaLabel': '拡大',
    'controls.zoomOut.ariaLabel': '縮小',
    'controls.fitView.ariaLabel': '全体を表示',
};

function borderOf(node: DiagramInputNode): string {
    return node.stepState === undefined
        ? 'border-border'
        : BORDER_CLASSES[node.stepState.state];
}

/** 辺をつなぐ点(読み取り専用なので見せない) */
function Handles() {
    return (
        <>
            <Handle
                type="target"
                position={Position.Top}
                isConnectable={false}
                className="opacity-0"
            />
            <Handle
                type="source"
                position={Position.Bottom}
                isConnectable={false}
                className="opacity-0"
            />
        </>
    );
}

/** 人の並び(自分には「(自分)」) */
function PeopleText({ node }: { node: DiagramInputNode }) {
    if (node.people.length === 0) {
        return null;
    }

    const text = node.people
        .map((person) => `${personName(person)}${person.isMe ? '(自分)' : ''}`)
        .join('、');

    return (
        <span className="min-w-0 truncate" title={text}>
            {text}
        </span>
    );
}

/** 申請・承認と展開の行の箱: 名前・短い文・人と状態の札 */
function StepNode({ data, parentId }: NodeProps<Node<StepNodeData>>) {
    const { node } = data;
    // 枠の子の箱は幅が狭いので、札を人の行の下に置く(人の名前と「処理済み(<種別>)」が切れずに入る)
    const isChild = parentId !== undefined;

    return (
        <div
            className={cn(
                'flex size-full flex-col justify-center gap-1 rounded-lg border-2 bg-card px-3 py-2 text-card-foreground',
                borderOf(node),
            )}
        >
            <Handles />
            <span className="truncate text-sm font-medium" title={node.name}>
                {node.name}
            </span>
            {node.subtitle !== null && (
                <span
                    className="truncate text-xs text-muted-foreground"
                    title={node.subtitle}
                >
                    {node.subtitle}
                </span>
            )}
            {node.stepState !== undefined && (
                <span
                    className={cn(
                        'flex min-w-0 gap-x-2 gap-y-1 text-xs',
                        isChild ? 'flex-col items-start' : 'items-center',
                    )}
                >
                    <PeopleText node={node} />
                    <span className={cn('shrink-0', !isChild && 'ml-auto')}>
                        <CaseStepBadge
                            stepState={node.stepState}
                            action={node.processed?.action ?? null}
                            className="text-xs"
                        />
                    </span>
                </span>
            )}
        </div>
    );
}

/** 分岐・同期の小さな箱(分岐開始は「分岐「{名前}」」) */
function RoutingNode({ data }: NodeProps<Node<StepNodeData>>) {
    const { node } = data;

    return (
        <div
            className={cn(
                'flex size-full flex-col items-center justify-center rounded-md border-2 bg-card px-2 text-center text-card-foreground',
                borderOf(node),
            )}
        >
            <Handles />
            <span className="max-w-full truncate text-xs font-medium">
                {node.kind === 'branch_start'
                    ? `分岐「${node.name}」`
                    : node.name}
            </span>
            {node.stepState !== undefined && (
                <span className="text-[11px] text-muted-foreground">
                    {node.stepState.label}
                </span>
            )}
        </div>
    );
}

/** 横配置・縦配置の枠: 見出し(種類と名前・短い文・状態の札)、子は React Flow の子ノード、縦配置は完了の条件と下辺の合流の印 */
function FrameNode({ data }: NodeProps<Node<FrameNodeData>>) {
    const { node, childCount } = data;

    return (
        <div
            className={cn(
                'relative size-full rounded-xl border-2 bg-muted/40 text-card-foreground',
                borderOf(node),
            )}
        >
            <Handles />
            {/* 見出しは 2 行(種類と名前・状態の札 / 短い文)。子が 2 つの狭い枠でも切れない */}
            <div
                className="flex flex-col justify-center gap-1 px-4"
                style={{ height: FRAME_HEADER_HEIGHT }}
            >
                <span className="flex min-w-0 items-center gap-2">
                    <span className="truncate text-sm font-medium">
                        {`${FRAME_KIND_LABELS[node.kind] ?? ''}「${node.name}」`}
                    </span>
                    {node.stepState !== undefined && (
                        <StepStateBadge
                            stepState={node.stepState}
                            className="text-xs"
                        />
                    )}
                </span>
                {node.subtitle !== null && (
                    <span className="truncate text-xs text-muted-foreground">
                        {node.subtitle}
                    </span>
                )}
            </div>
            {childCount === 0 && node.awaitingDesignation === true && (
                <p className="px-4 text-xs text-muted-foreground">
                    処理対象者はまだ指定されていません
                </p>
            )}
            {node.consensus !== null && (
                <div className="absolute inset-x-4 bottom-4 flex h-7 items-center justify-center rounded-full border bg-card text-xs">
                    {node.consensus.text}
                </div>
            )}
            {node.kind === 'parallel' && (
                <span
                    aria-hidden="true"
                    className="absolute -bottom-1.5 left-1/2 size-3 -translate-x-1/2 rounded-full border-2 border-current bg-card"
                />
            )}
        </div>
    );
}

/** 最後の後の「終了」の印(終了のノードは持たない = ルートの深さ/画面.md 8 節。図の上の印だけ) */
function EndNode() {
    return (
        <div className="flex size-full items-center justify-center rounded-full border bg-card text-xs text-muted-foreground">
            <Handle
                type="target"
                position={Position.Top}
                isConnectable={false}
                className="opacity-0"
            />
            終了
        </div>
    );
}

/** 辺(配置の計算が作った直角の道。通らなかった枝とその続きは点線、通らなかった枝の最初の辺は語も) */
function RouteEdge({ data, markerEnd }: EdgeProps<Edge<RouteEdgeData>>) {
    if (data === undefined) {
        return null;
    }

    return (
        <>
            <BaseEdge
                path={data.path}
                markerEnd={markerEnd}
                style={data.dashed ? { strokeDasharray: '6 4' } : undefined}
            />
            {data.text !== null && (
                <EdgeLabelRenderer>
                    <span
                        className={cn(
                            'absolute rounded-full border bg-card px-2 py-0.5 text-xs whitespace-nowrap',
                            data.dashed &&
                                'border-dashed text-muted-foreground',
                        )}
                        style={{
                            transform: `translate(-50%, -50%) translate(${data.labelX}px, ${data.labelY}px)`,
                        }}
                    >
                        {data.text}
                    </span>
                </EdgeLabelRenderer>
            )}
        </>
    );
}

/**
 * 図の面の大きさか配置が変わったら、全体が入る表示をやり直す(ReactFlow の fitView は最初の表示だけ。詳細の画面は操作の後も状態を保つので、
 * 合議の進み具合の面が出て幅が変わる・配置が変わる場面に効かせる)。ReactFlow の内側に置く(useReactFlow はその中でだけ使える)。
 */
function FitViewOnChange({ layoutKey }: { layoutKey: object }) {
    const { fitView } = useReactFlow();
    const width = useStore((state) => state.width);
    const height = useStore((state) => state.height);

    useEffect(() => {
        void fitView(FIT_VIEW_OPTIONS);
    }, [fitView, width, height, layoutKey]);

    return null;
}

const NODE_TYPES = {
    step: StepNode,
    routing: RoutingNode,
    frame: FrameNode,
    end: EndNode,
};
const EDGE_TYPES = { route: RouteEdge };

/**
 * フロー図(docs の 設計/ワークフロー/ルートの深さ/画面.md 8 節。定義の図と案件の図を同じ部品で描く): 読み取り専用(動かす・つなぐ・消す・選ぶはできない)で、
 * 拡大・縮小と移動だけを残す。色だけで伝えないよう、案件の図は状態の語を箱に出す。読み上げとキーボードは表(フロー情報の表)で同じ情報を得る。
 */
export function WorkflowDiagram({
    nodes,
    edges,
    label,
}: {
    nodes: DiagramInputNode[];
    edges: WorkflowDiagramEdge[];
    label: string;
}) {
    const layout = useMemo(() => layoutDiagram(nodes, edges), [nodes, edges]);

    return (
        <div
            role="group"
            aria-label={label}
            className="w-full rounded-lg border bg-background [--xy-attribution-background-color:transparent] [--xy-controls-button-background-color-hover:var(--accent)] [--xy-controls-button-background-color:var(--card)] [--xy-controls-button-border-color:var(--border)] [--xy-controls-button-color-hover:var(--accent-foreground)] [--xy-controls-button-color:var(--foreground)] [--xy-edge-stroke:var(--muted-foreground)]"
        >
            {/* 高さは枠線の内側の要素に与える(枠線の分だけ配置より低くならない = 1 倍で全体が入る) */}
            <div style={{ height: Math.min(layout.height, MAX_HEIGHT) }}>
                <ReactFlow
                    nodes={layout.nodes}
                    edges={layout.edges}
                    nodeTypes={NODE_TYPES}
                    edgeTypes={EDGE_TYPES}
                    nodesDraggable={false}
                    nodesConnectable={false}
                    elementsSelectable={false}
                    nodesFocusable={false}
                    edgesFocusable={false}
                    zoomOnScroll={false}
                    preventScrolling={false}
                    fitView
                    fitViewOptions={FIT_VIEW_OPTIONS}
                    minZoom={0.3}
                    maxZoom={1.5}
                    defaultMarkerColor="var(--muted-foreground)"
                    ariaLabelConfig={ARIA_LABELS}
                >
                    <Controls
                        showInteractive={false}
                        position="top-right"
                        fitViewOptions={FIT_VIEW_OPTIONS}
                    />
                    <FitViewOnChange layoutKey={layout} />
                </ReactFlow>
            </div>
        </div>
    );
}
