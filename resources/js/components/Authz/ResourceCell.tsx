import { ChevronDown, ChevronRight } from 'lucide-react';
import { GridHead } from '@/components/Authz/GridCells';
import { Badge } from '@/components/ui/badge';
import { blockedLabel } from '@/lib/Authz/labels';
import { cn } from '@/lib/utils';

type Props = {
    name: string;
    depth: number;
    /** 子のある行(名前を太字にする) */
    hasChildren: boolean;
    /** 開閉の記号。null なら記号を出さず、字下げ揃えの空白を置く(子の無い行・検索中) */
    toggle: { isOpen: boolean; onToggle: () => void } | null;
    /** 判定が閉塞のアクション(空 = 閉塞の行ではない) */
    blocked: string[];
    /** 格子のアクションのすべて */
    actions: string[];
};

/**
 * 先頭の列(行の見出し)。深さ × 16px の字下げ、開閉の記号(toggle が null なら出さない)、閉塞の行の札。止まる列の地は不透明。
 * 読み上げの名前はリソース名だけ(開閉の記号・札を含めない)。
 */
export function ResourceCell({
    name,
    depth,
    hasChildren,
    toggle,
    blocked,
    actions,
}: Props) {
    const isBlocked = blocked.length > 0;

    return (
        <GridHead
            scope="row"
            aria-label={name}
            className={cn(
                'sticky left-0 z-10 h-12 min-w-64 border-b font-normal group-last/row:border-b-0 group-hover/row:bg-muted',
                hasChildren && 'font-medium',
                isBlocked &&
                    'bg-status-rejected text-status-rejected-foreground group-hover/row:bg-status-rejected',
            )}
        >
            <div
                className="flex items-center gap-1"
                style={{ paddingLeft: depth * 16 }}
            >
                {toggle !== null ? (
                    <button
                        type="button"
                        aria-expanded={toggle.isOpen}
                        aria-label={`${name}の配下を${toggle.isOpen ? '非表示' : '表示'}`}
                        onClick={toggle.onToggle}
                        className="inline-flex size-6 flex-none items-center justify-center rounded-md text-muted-foreground outline-none hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        {toggle.isOpen ? (
                            <ChevronDown
                                className="size-4"
                                aria-hidden="true"
                            />
                        ) : (
                            <ChevronRight
                                className="size-4"
                                aria-hidden="true"
                            />
                        )}
                    </button>
                ) : (
                    <span className="size-6 flex-none" aria-hidden="true" />
                )}
                <span>{name}</span>
                {isBlocked && (
                    <Badge className="bg-status-rejected-foreground text-status-rejected">
                        {blockedLabel(blocked, actions)}
                    </Badge>
                )}
            </div>
        </GridHead>
    );
}
