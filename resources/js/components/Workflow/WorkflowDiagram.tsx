import { Controls, ReactFlow, useReactFlow, useStore } from '@xyflow/react';
import type { FitViewOptions, Node, NodeProps } from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import { useEffect, useMemo } from 'react';
import {
    JointNode,
    NoopNode,
    RoutingNode,
} from '@/components/Workflow/DiagramMarks';
import {
    EndNode,
    Handles,
    PlaceholderNode,
    RouteEdge,
    StepNode,
    borderOf,
} from '@/components/Workflow/DiagramParts';
import { FullscreenButton } from '@/components/Workflow/FullscreenButton';
import {
    SelectionContext,
    codeOfNode,
    useNodeSelection,
} from '@/hooks/Workflow/use-diagram-selection';
import type { DiagramSelection } from '@/hooks/Workflow/use-diagram-selection';
import { useDiagramFullscreen } from '@/hooks/Workflow/use-diagram-fullscreen';
import type { DiagramFullscreen } from '@/hooks/Workflow/use-diagram-fullscreen';
import { cn } from '@/lib/utils';
import {
    FRAME_HEADER_HEIGHT,
    frameHeaderTexts,
} from '@/lib/Workflow/diagramFrames';
import { DIAGRAM_MARGIN, layoutDiagram } from '@/lib/Workflow/diagramLayout';
import type {
    DiagramInputNode,
    FrameNodeData,
} from '@/lib/Workflow/diagramTypes';
import type { WorkflowDiagramEdge } from '@/types';

/** 図の高さの上限(px。版 3 の図が 1 倍で入る高さ)。超えたら 0.6 倍まで縮め、なお入らなければ図の中で移動(設計/ワークフロー/ルートの深さ/画面/図と意匠とE2E.md 8 節) */
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

/** React Flow の色の変数を画面のトークンに合わせる(操作のボタン・線・帰属の表示) */
const DIAGRAM_COLOR_VARIABLES = [
    '[--xy-attribution-background-color:transparent]',
    '[--xy-controls-button-background-color-hover:var(--accent)]',
    '[--xy-controls-button-background-color:var(--card)]',
    '[--xy-controls-button-border-color:var(--border)]',
    '[--xy-controls-button-color-hover:var(--accent-foreground)]',
    '[--xy-controls-button-color:var(--foreground)]',
    '[--xy-edge-stroke:var(--muted-foreground)]',
];

/** 配置の種類の語(枠の見出し)。サーバーの App\Queries\Workflow\CaseDesignations::KIND_LABELS の写し(tests/Architecture/WorkflowDiagramMirrorTest.php が結ぶ) */
const FRAME_KIND_LABELS: Partial<Record<DiagramInputNode['kind'], string>> = {
    serial: '順次承認',
    parallel: '並列承認',
};

/** 拡大・縮小の操作の読み上げの名前(React Flow の既定は英語) */
const ARIA_LABELS = {
    'controls.ariaLabel': '図の表示の操作',
    'controls.zoomIn.ariaLabel': '拡大',
    'controls.zoomOut.ariaLabel': '縮小',
    'controls.fitView.ariaLabel': '全体を表示',
};

/**
 * 枠の見出し(左に種類と名前、右に短い文の 1 行。状態の札は出さない = 状態は子の箱の札と縁で読む)。右の短い文は切らない
 * (枠の幅は見出しが入る幅以上 = diagramFrames.ts の frameHeaderWidth。左の名前は見積もりを超えたときだけ切る)。
 */
function FrameHeader({ node }: { node: DiagramInputNode }) {
    const { title, subtitle } = frameHeaderTexts(
        node,
        FRAME_KIND_LABELS[node.kind] ?? '',
    );

    return (
        <div
            className="flex items-center justify-between gap-3 px-4"
            style={{ height: FRAME_HEADER_HEIGHT }}
        >
            <span className="min-w-0 truncate text-sm font-medium">
                {title}
            </span>
            {subtitle !== null && (
                <span className="shrink-0 text-xs whitespace-nowrap text-muted-foreground">
                    {subtitle}
                </span>
            )}
        </div>
    );
}

/**
 * 横配置・縦配置の枠(破線): 見出し、子は React Flow の子ノード、縦配置は完了の条件の札(合流は枠の下の「同期終了」の札 = DiagramMarks.tsx)
 */
function FrameNode({ data }: NodeProps<Node<FrameNodeData>>) {
    const { node, childCount } = data;
    const { isSelected, props } = useNodeSelection(node);

    return (
        <div
            {...props}
            className={cn(
                'relative size-full rounded-xl border-2 border-dashed bg-muted/40',
                'text-card-foreground',
                borderOf(node, isSelected),
                props.className,
            )}
        >
            <Handles />
            <FrameHeader node={node} />
            {childCount === 0 && node.awaitingDesignation === true && (
                <p className="px-4 text-xs text-muted-foreground">
                    承認者はまだ選択されていません
                </p>
            )}
            {node.consensus !== null && (
                <div
                    className={cn(
                        'absolute inset-x-4 bottom-4 flex h-7 items-center justify-center rounded-full border',
                        'border-status-pending-foreground/40 bg-status-pending text-xs font-medium',
                        'text-status-pending-foreground',
                    )}
                >
                    {node.consensus.text}
                </div>
            )}
        </div>
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
    placeholder: PlaceholderNode,
    noop: NoopNode,
    joint: JointNode,
    end: EndNode,
};
const EDGE_TYPES = { route: RouteEdge };

/**
 * 箱を押したときの処理(選べる図のときだけ。押した箱がステップならそのコードで選ぶ)。
 */
function nodeClickOf(selection: DiagramSelection | undefined) {
    if (selection === undefined) {
        return undefined;
    }

    return (_: unknown, node: Node) => {
        const code = codeOfNode(node);

        if (code !== null) {
            selection.onSelect(code);
        }
    };
}

/**
 * React Flow の本体(読み取り専用。拡大・縮小と移動の操作と、全体が入る表示のやり直し)。
 */
function DiagramFlow({
    layout,
    selection,
    fullscreen,
}: {
    layout: ReturnType<typeof layoutDiagram>;
    selection: DiagramSelection | undefined;
    fullscreen: DiagramFullscreen;
}) {
    return (
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
            onNodeClick={nodeClickOf(selection)}
        >
            <Controls
                showInteractive={false}
                position="top-right"
                fitViewOptions={FIT_VIEW_OPTIONS}
            >
                <FullscreenButton fullscreen={fullscreen} />
            </Controls>
            <FitViewOnChange layoutKey={layout} />
        </ReactFlow>
    );
}

/**
 * フロー図(docs の 設計/ワークフロー/ルートの深さ/画面/図と意匠とE2E.md 8 節。定義の図と案件の図を同じ部品で描く):
 * 動かす・つなぐ・消すはできず、拡大・縮小と移動だけを残す。ステップを押して選べるのは selection を渡した定義の図だけ
 * (選んだ箱は縁を太く色をつけ、aria-pressed も持つ)。色だけで伝えないよう、案件の図は状態の語を箱に出す。
 * 読み上げとキーボードは表(フロー情報の表)や、定義の図の下のステップの名前のボタンで同じ情報を得る。
 */
export function WorkflowDiagram({
    nodes,
    edges,
    label,
    selection,
}: {
    nodes: DiagramInputNode[];
    edges: WorkflowDiagramEdge[];
    label: string;
    /** 渡すと、ステップを押して選べる(定義の図)。渡さない案件の図は押せない */
    selection?: DiagramSelection;
}) {
    const layout = useMemo(() => layoutDiagram(nodes, edges), [nodes, edges]);
    const fullscreen = useDiagramFullscreen();

    return (
        <div
            ref={fullscreen.elementRef}
            role="group"
            aria-label={label}
            className={cn(
                'w-full rounded-lg border bg-background',
                fullscreen.isFullscreen && 'rounded-none border-0',
                DIAGRAM_COLOR_VARIABLES,
            )}
        >
            {/* 高さは枠線の内側の要素に与える(枠線の分だけ配置より低くならない = 1 倍で全体が入る)。全画面の間は画面の高さいっぱい */}
            <div
                style={{
                    height: fullscreen.isFullscreen
                        ? '100%'
                        : Math.min(layout.height, MAX_HEIGHT),
                }}
            >
                <SelectionContext.Provider value={selection ?? null}>
                    <DiagramFlow
                        layout={layout}
                        selection={selection}
                        fullscreen={fullscreen}
                    />
                </SelectionContext.Provider>
            </div>
        </div>
    );
}
