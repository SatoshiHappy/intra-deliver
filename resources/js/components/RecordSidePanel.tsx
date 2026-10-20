import { History, MessageSquare, Paperclip, PanelRight } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import type { RecordPanelItem } from '@/types';

/** 欄の種類のアイコン(業務ツールの節は口がアイコンを返さないので共通 = 詳細の画面/README.md の 8 節) */
const ICONS: Record<RecordPanelItem['kind'], LucideIcon> = {
    attachments: Paperclip,
    comments: MessageSquare,
    revisions: History,
    section: PanelRight,
};

/** 帯とパネルを枠の中に固定する(左の詳細だけが流れる = 1 節) */
const PINNED =
    'sticky top-[calc(var(--header-height)+1rem)] max-h-[calc(100svh-var(--header-height)-2rem)]';

/** 帯のアイコンの右上の件数 */
const COUNT = cn(
    'absolute top-px right-1 min-w-[18px] rounded-full bg-primary px-1',
    'font-num text-[11px] leading-[18px] font-semibold text-primary-foreground tabular-nums',
);

/** 帯の読み上げ(件数を出す欄は名前と件数) */
function itemLabel(item: RecordPanelItem): string {
    return item.count === null ? item.name : `${item.name}　${item.count} 件`;
}

function RailItem({
    item,
    pressed,
    onToggle,
}: {
    item: RecordPanelItem;
    pressed: boolean;
    onToggle: (key: string) => void;
}) {
    const Icon = ICONS[item.kind];

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    aria-pressed={pressed}
                    aria-label={itemLabel(item)}
                    onClick={() => onToggle(item.key)}
                    className={cn(
                        'relative grid w-[62px] justify-items-center gap-0.5 rounded-md pt-2 pb-1.5',
                        'text-[11px] leading-tight font-medium text-muted-foreground hover:text-foreground',
                        pressed &&
                            'bg-background font-bold text-primary shadow-[0_0_0_1px_var(--border)] hover:text-primary',
                    )}
                >
                    <Icon className="size-[22px]" aria-hidden="true" />
                    <span>{item.shortName}</span>
                    {item.count !== null && (
                        <span className={COUNT}>{item.count}</span>
                    )}
                </button>
            </TooltipTrigger>
            <TooltipContent side="left">{item.name}</TooltipContent>
        </Tooltip>
    );
}

/** 開いた欄のパネル(頭 = 欄の名前と件数と「»」、中身はパネルの中で流れる) */
function Pane({
    item,
    onClose,
    children,
}: {
    item: RecordPanelItem;
    onClose: () => void;
    children: ReactNode;
}) {
    return (
        <section
            aria-label={item.name}
            className={cn(
                PINNED,
                'flex min-w-0 flex-col rounded-xl border bg-card text-card-foreground',
            )}
        >
            <div className="flex items-center gap-2 rounded-t-xl border-b bg-muted px-3 py-1.5">
                <h2 className="font-bold">{item.name}</h2>
                {item.count !== null && (
                    <span className="text-sm text-muted-foreground">{`${item.count} 件`}</span>
                )}
                <Tooltip>
                    <TooltipTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="ml-auto text-xl font-bold text-muted-foreground"
                            aria-label="サイドパネルを非表示にする"
                            onClick={onClose}
                        >
                            »
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>サイドパネルを非表示にする</TooltipContent>
                </Tooltip>
            </div>
            <div className="min-h-0 flex-1 overflow-y-auto px-4 pt-3.5 pb-4 text-sm">
                {children}
            </div>
        </section>
    );
}

/**
 * サイドパネル(詳細の画面/README.md の 1 節・2 節): 右端の帯と、押した欄だけが開くパネル。開く欄は 1 つ、同じアイコンか「»」で閉じる。
 * 欄の中身は children(呼び手が開いた欄の中身を描く)。{@link RecordShowLayout} の side に置く(パネルと帯の 2 つの格子の子)。
 */
export function RecordSidePanel({
    items,
    openKey,
    onToggle,
    onClose,
    children,
}: {
    items: RecordPanelItem[];
    /** 開いている欄のキー(null = 閉じた形) */
    openKey: string | null;
    onToggle: (key: string) => void;
    onClose: () => void;
    children: ReactNode;
}) {
    const open = items.find((item) => item.key === openKey);

    return (
        <>
            {open !== undefined && (
                <Pane item={open} onClose={onClose}>
                    {children}
                </Pane>
            )}
            <nav
                aria-label="サイドパネル"
                className={cn(
                    PINNED,
                    'grid w-[72px] content-start justify-items-center gap-0.5',
                    'overflow-y-auto rounded-xl border bg-muted py-2',
                )}
            >
                {items.map((item) => (
                    <RailItem
                        key={item.key}
                        item={item}
                        pressed={item.key === openKey}
                        onToggle={onToggle}
                    />
                ))}
            </nav>
        </>
    );
}
