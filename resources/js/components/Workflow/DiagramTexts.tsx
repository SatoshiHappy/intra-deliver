import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import {
    CONFIRM_BADGE_SPACE,
    subtitleText,
} from '@/lib/Workflow/diagramFrames';
import type { WorkflowSubtitlePart } from '@/types';

/**
 * フロー図の箱の短い文と確認の札(docs の 設計/ワークフロー/ルートの深さ/画面/図と意匠とE2E.md の 9.3 節、
 * 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.6 節)。
 */

/**
 * 短い文(強調の区切りは文字の色を本文の色にする。太字にしない = 図と意匠とE2E.md 9.3 節)。切れたときは title で全文を見せる。
 */
export function SubtitleText({
    parts,
    className,
}: {
    parts: WorkflowSubtitlePart[];
    className?: string;
}) {
    return (
        <span
            className={cn('text-xs text-muted-foreground', className)}
            title={subtitleText(parts)}
        >
            {parts.map((part, index) =>
                typeof part === 'string' ? (
                    part
                ) : (
                    <span key={index} className="text-foreground">
                        {part.text}
                    </span>
                ),
            )}
        </span>
    );
}

/**
 * 箱の下の中央の確認の札(確認する人がいるステップ。地と縁は完了の条件の札と同じトークン)。
 */
function ConfirmBadge({ text }: { text: string }) {
    return (
        <span
            className={cn(
                'flex h-[22px] max-w-full shrink-0 items-center rounded-full border px-3',
                'border-status-pending-foreground/40 bg-status-pending text-xs font-bold',
                'text-status-pending-foreground',
            )}
        >
            <span className="truncate" title={text}>
                {text}
            </span>
        </span>
    );
}

/**
 * 箱と、その下の中央の確認の札を縦に並べる(配置の箱は札の分だけ高い = diagramLayout.ts。辺をつなぐ点 handles は全体の上下)。
 */
export function WithConfirmBadge({
    text,
    handles,
    children,
}: {
    text: string;
    handles: ReactNode;
    children: ReactNode;
}) {
    return (
        <div
            className="flex size-full flex-col items-center"
            style={{ gap: CONFIRM_BADGE_SPACE.gap }}
        >
            {handles}
            <div className="min-h-0 w-full flex-1">{children}</div>
            <ConfirmBadge text={text} />
        </div>
    );
}
