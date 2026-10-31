import { BaseEdge, EdgeLabelRenderer, Handle, Position } from '@xyflow/react';
import type { Edge, EdgeProps, Node, NodeProps } from '@xyflow/react';
import { CaseStepBadge } from '@/components/Workflow/WorkflowStatusBadge';
import { useNodeSelection } from '@/hooks/Workflow/use-diagram-selection';
import { cn } from '@/lib/utils';
import type {
    DiagramInputNode,
    PlaceholderNodeData,
    RouteEdgeData,
    StepNodeData,
} from '@/lib/Workflow/diagramTypes';
import { personName } from '@/lib/Workflow/workflowLabels';
import type { WorkflowStepState } from '@/types';

/**
 * フロー図の箱と辺の部品(申請・承認・分岐・同期・終了・仮の札・辺。枠は WorkflowDiagram.tsx)。
 * docs の 設計/ワークフロー/ルートの深さ/画面/図と意匠とE2E.md 8 節。
 */

/**
 * 状態ごとの箱の縁(状態色 = 札と同じトークン。到達していないは既定の縁、省略は薄い破線の縁。
 * 設計/ワークフロー/ルートの深さ/画面/図と意匠とE2E.md 8 節)
 */
const BORDER_CLASSES: Record<WorkflowStepState['state'], string> = {
    hold: 'border-status-returned-foreground',
    done: 'border-status-settled-foreground/70',
    current: 'border-status-pending-foreground',
    returned: 'border-status-returned-foreground',
    upcoming: 'border-border',
    skipped: 'border-dashed border-border text-muted-foreground',
};

/** 選んだステップの印(縁を太く、色をつける。色だけに頼らず aria-pressed も持つ) */
const SELECTED_CLASSES = 'border-primary ring-2 ring-primary';

export function borderOf(node: DiagramInputNode, isSelected = false): string {
    return cn(
        BORDER_CLASSES[node.stepState?.state ?? 'upcoming'],
        isSelected && SELECTED_CLASSES,
    );
}

/** 辺をつなぐ点(読み取り専用なので見せない) */
export function Handles() {
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
export function StepNode({ data, parentId }: NodeProps<Node<StepNodeData>>) {
    const { node } = data;
    // 枠の子の箱は幅が狭いので、札を人の行の下に置く(人の名前と「処理済み(<種別>)」が切れずに入る)
    const isChild = parentId !== undefined;
    const { isSelected, props } = useNodeSelection(node);

    return (
        <div
            {...props}
            className={cn(
                'flex size-full flex-col justify-center gap-1 rounded-lg',
                'border-2 bg-card px-3 py-2 text-card-foreground',
                borderOf(node, isSelected),
                props.className,
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
export function RoutingNode({ data }: NodeProps<Node<StepNodeData>>) {
    const { node } = data;
    const { isSelected, props } = useNodeSelection(node);

    return (
        <div
            {...props}
            className={cn(
                'flex size-full flex-col items-center justify-center',
                'rounded-md border-2 bg-card px-2 text-center text-card-foreground',
                borderOf(node, isSelected),
                props.className,
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

/**
 * 定義の図の枠の中の仮の札(「承認 1」と「実行時に指定」、残りをまとめた札は「承認 3 から 5」と「任意」。
 * 設計/ワークフロー/ルートの深さ/画面/図と意匠とE2E.md 8 節)。実行時に決まる席なので破線の縁。
 */
export function PlaceholderNode({
    data,
}: NodeProps<Node<PlaceholderNodeData>>) {
    return (
        <div
            className={cn(
                'flex size-full flex-col justify-center gap-1',
                'rounded-lg border-2 border-dashed bg-card px-3 py-2',
                'text-card-foreground',
            )}
        >
            <Handles />
            <span className="truncate text-sm font-medium" title={data.label}>
                {data.label}
            </span>
            <span className="text-xs text-muted-foreground">
                {data.optional ? '任意' : '実行時に指定'}
            </span>
        </div>
    );
}

/** 最後の後の「終了」の印(終了のノードは持たない = 図と意匠とE2E.md 8 節。図の上の印だけ) */
export function EndNode() {
    return (
        <div
            className={cn(
                'flex size-full items-center justify-center rounded-full',
                'border bg-card text-xs text-muted-foreground',
            )}
        >
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
export function RouteEdge({ data, markerEnd }: EdgeProps<Edge<RouteEdgeData>>) {
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
                            'absolute rounded-full border bg-card px-2 py-0.5',
                            'text-xs whitespace-nowrap',
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
