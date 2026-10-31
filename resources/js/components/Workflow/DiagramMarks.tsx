import type { Node, NodeProps } from '@xyflow/react';
import type { ReactNode } from 'react';
import { Handles } from '@/components/Workflow/DiagramParts';
import { useNodeSelection } from '@/hooks/Workflow/use-diagram-selection';
import { routingToneOf } from '@/lib/Workflow/diagramMarks';
import type {
    JointNodeData,
    NoopNodeData,
    RoutingTone,
    StepNodeData,
} from '@/lib/Workflow/diagramTypes';
import { cn } from '@/lib/utils';

/**
 * フロー図の分岐・同期の印と、図の側で足す箱と札(docs の 設計/ワークフロー/ルートの深さ/画面/図と意匠とE2E.md 8 節):
 * 分岐開始・分岐終了 = 横長のひし形、同期開始・同期終了と縦配置の下の「同期終了」= 長円の札、ステップの無い枝 =「何もしない」の箱。
 * 分岐・同期は札を出さず、通ったことを塗りで示す(省略は破線)。
 */

/** ひし形の線(縁の色 = 箱の縁と同じトークン。選んだら太く色をつける) */
const DIAMOND_TONES: Record<RoutingTone, string> = {
    plain: 'fill-card stroke-border',
    passed: 'fill-status-settled stroke-status-settled-foreground/70',
    skipped: 'fill-card stroke-border',
};

/** 長円の札の縁と塗り */
const PILL_TONES: Record<RoutingTone, string> = {
    plain: 'border-border bg-card',
    passed: 'border-status-settled-foreground/70 bg-status-settled',
    skipped: 'border-dashed border-border bg-card text-muted-foreground',
};

/** 横長のひし形(中に名前。線は箱の大きさに伸ばしても太さを変えない) */
function Diamond({
    tone,
    isSelected,
    children,
}: {
    tone: RoutingTone;
    isSelected: boolean;
    children: ReactNode;
}) {
    return (
        <>
            <svg
                aria-hidden="true"
                viewBox="0 0 100 100"
                preserveAspectRatio="none"
                className="absolute inset-0 size-full overflow-visible"
            >
                <polygon
                    points="1,50 50,1 99,50 50,99"
                    vectorEffect="non-scaling-stroke"
                    strokeWidth={isSelected ? 3 : 2}
                    strokeDasharray={tone === 'skipped' ? '6 4' : undefined}
                    className={cn(
                        DIAMOND_TONES[tone],
                        isSelected && 'stroke-primary',
                    )}
                />
            </svg>
            <span
                className={cn(
                    'relative max-w-[70%] truncate text-xs font-medium',
                    tone === 'skipped' && 'text-muted-foreground',
                )}
            >
                {children}
            </span>
        </>
    );
}

/** 長円の札(同期と、縦配置の下の「同期終了」) */
function Pill({
    tone,
    className,
    children,
}: {
    tone: RoutingTone;
    className?: string;
    children: ReactNode;
}) {
    return (
        <span
            className={cn(
                'flex size-full items-center justify-center rounded-full border-2 px-3',
                'text-xs font-medium text-card-foreground',
                PILL_TONES[tone],
                className,
            )}
        >
            <span className="truncate">{children}</span>
        </span>
    );
}

/** 分岐・同期の印(分岐開始は「分岐「{名前}」」、ほかはノードの名前) */
export function RoutingNode({ data }: NodeProps<Node<StepNodeData>>) {
    const { node } = data;
    const { isSelected, props } = useNodeSelection(node);
    const tone = routingToneOf(node.stepState);
    const name =
        node.kind === 'branch_start' ? `分岐「${node.name}」` : node.name;
    const isDiamond =
        node.kind === 'branch_start' || node.kind === 'branch_end';

    return (
        <div
            {...props}
            title={name}
            className={cn(
                'relative flex size-full items-center justify-center',
                props.className,
            )}
        >
            <Handles />
            {isDiamond ? (
                <Diamond tone={tone} isSelected={isSelected}>
                    {name}
                </Diamond>
            ) : (
                <Pill
                    tone={tone}
                    className={cn(
                        isSelected && 'border-primary ring-2 ring-primary',
                    )}
                >
                    {name}
                </Pill>
            )}
        </div>
    );
}

/** 縦配置の枠の下の「同期終了」の札(図の側で足す。押せない) */
export function JointNode({ data }: NodeProps<Node<JointNodeData>>) {
    return (
        <div className="relative size-full">
            <Handles />
            <Pill tone={data.tone}>{data.label}</Pill>
        </div>
    );
}

/**
 * ステップの無い枝の「何もしない」の箱(図の側で足す。押せない)。2 行目は定義の図 =「そのまま次へ進む」、案件の図で通った =「そのまま次へ進んだ」、
 * 通らなかった = 破線の箱と「通らなかった枝」。
 */
export function NoopNode({ data }: NodeProps<Node<NoopNodeData>>) {
    const untaken = data.taken === false;
    const note =
        data.taken === null
            ? 'そのまま次へ進む'
            : untaken
              ? '通らなかった枝'
              : 'そのまま次へ進んだ';

    return (
        <div
            className={cn(
                'flex size-full flex-col items-center justify-center gap-1 rounded-lg',
                'border-2 bg-card px-3 py-2 text-center text-card-foreground',
                untaken
                    ? 'border-dashed border-border text-muted-foreground'
                    : 'border-border',
            )}
        >
            <Handles />
            <span className="text-sm font-medium">何もしない</span>
            <span className="text-xs text-muted-foreground">{note}</span>
        </div>
    );
}
