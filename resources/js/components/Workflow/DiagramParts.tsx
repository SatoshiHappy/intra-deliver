import { BaseEdge, EdgeLabelRenderer, Handle, Position } from '@xyflow/react';
import type { Edge, EdgeProps, Node, NodeProps } from '@xyflow/react';
import { CaseStepBadge } from '@/components/Workflow/WorkflowStatusBadge';
import { useNodeSelection } from '@/hooks/Workflow/use-diagram-selection';
import { cn } from '@/lib/utils';
import { councilBoxTitle } from '@/lib/Workflow/diagramFrames';
import { stepBoxToneOf } from '@/lib/Workflow/diagramMarks';
import type {
    DiagramInputNode,
    PlaceholderNodeData,
    RouteEdgeData,
    StepNodeData,
} from '@/lib/Workflow/diagramTypes';
import { personName } from '@/lib/Workflow/workflowLabels';
import type { WorkflowStepState } from '@/types';

/**
 * フロー図の箱と辺の部品(申請・承認・終了・仮の札・辺。分岐・同期と図の側で足す箱と札は DiagramMarks.tsx、枠は WorkflowDiagram.tsx)。
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

/** 箱の地(処理済み = 処理済みの札の地、承認待ち = 承認待ちの札の地と太い縁。stepBoxToneOf) */
const TONE_CLASSES: Record<ReturnType<typeof stepBoxToneOf>, string> = {
    settled: 'bg-status-settled',
    pending: 'border-[3px] bg-status-pending',
    plain: 'bg-card',
};

/**
 * 箱の人と状態の札の行(枠の子の箱は幅が狭いので、札を人の行の下に置く)。showsPeople = 人の並びを出す(並列承認の枠の中の箱は
 * 題が人の名前なので出さない)。地を塗った箱は地と札の地が同じ系統の色なので、札に縁を付ける。
 */
function StateLine({
    node,
    stepState,
    isChild,
    showsPeople,
    isOutlined,
}: {
    node: DiagramInputNode;
    stepState: WorkflowStepState;
    isChild: boolean;
    showsPeople: boolean;
    isOutlined: boolean;
}) {
    return (
        <span
            className={cn(
                'flex max-w-full min-w-0 items-center gap-x-2 gap-y-1 text-xs',
                isChild && 'flex-col',
            )}
        >
            {showsPeople && <PeopleText node={node} />}
            <span
                className={cn(
                    'shrink-0',
                    isOutlined && '[&>[data-slot=badge]]:border-current/30',
                )}
            >
                <CaseStepBadge
                    stepState={stepState}
                    action={node.processed?.action ?? null}
                    className="text-xs"
                />
            </span>
        </span>
    );
}

/**
 * 申請・承認と展開の行の箱: 題(ステップの名前。並列承認の枠の中の箱は人の名前 = councilBoxTitle)・短い文・人と状態の札。
 * 処理済み・承認待ちの箱は札を残して地を札の地で塗る(図と意匠とE2E.md 8 節)。
 */
export function StepNode({ data, parentId }: NodeProps<Node<StepNodeData>>) {
    const { node, inCouncil = false } = data;
    const { isSelected, props } = useNodeSelection(node);
    const tone = stepBoxToneOf(node.stepState);
    const title = inCouncil ? councilBoxTitle(node) : node.name;

    return (
        <div
            {...props}
            className={cn(
                'flex size-full flex-col items-center justify-center gap-1 rounded-lg',
                'border-2 px-3 py-2 text-center text-card-foreground',
                TONE_CLASSES[tone],
                borderOf(node, isSelected),
                props.className,
            )}
        >
            <Handles />
            <span
                className={cn(
                    'max-w-full truncate text-sm',
                    inCouncil ? 'font-semibold' : 'font-medium',
                )}
                title={title}
            >
                {title}
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
                <StateLine
                    node={node}
                    stepState={node.stepState}
                    isChild={parentId !== undefined}
                    showsPeople={!inCouncil}
                    isOutlined={tone !== 'plain'}
                />
            )}
        </div>
    );
}

/**
 * 定義の図の枠の中の仮の札(「承認 1」と「実行時に選択」、残りをまとめた札は「承認 3〜5」と「任意」。
 * 設計/ワークフロー/ルートの深さ/画面/図と意匠とE2E.md 8 節)。必ず要る席は実線、任意の席は破線の縁。
 */
export function PlaceholderNode({
    data,
}: NodeProps<Node<PlaceholderNodeData>>) {
    return (
        <div
            className={cn(
                'flex size-full flex-col items-center justify-center gap-1',
                'rounded-lg border-2 bg-card px-3 py-2 text-center',
                'text-card-foreground',
                data.optional && 'border-dashed',
            )}
        >
            <Handles />
            <span
                className="max-w-full truncate text-sm font-medium"
                title={data.label}
            >
                {data.label}
            </span>
            <span className="text-xs text-muted-foreground">
                {data.optional ? '任意' : '実行時に選択'}
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

/**
 * 辺(配置の計算が作った直角の道。通らなかった枝とその続きは点線)と枝の札(行き先の箱のすぐ上。上の行 = 辺の名前、下の行 = 条件の文。
 * 通らなかった枝の札は点線の縁)。
 */
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
                            'absolute flex flex-col items-center rounded-xl border bg-card px-2.5 py-0.5',
                            'leading-tight whitespace-nowrap',
                            data.dashed &&
                                'border-dashed text-muted-foreground',
                        )}
                        style={{
                            transform: `translate(-50%, -50%) translate(${data.labelX}px, ${data.labelY}px)`,
                        }}
                    >
                        <span className="text-xs font-medium">{data.text}</span>
                        {data.conditionText !== null && (
                            <span className="text-[11px] text-muted-foreground">
                                {data.conditionText}
                            </span>
                        )}
                    </span>
                </EdgeLabelRenderer>
            )}
        </>
    );
}
