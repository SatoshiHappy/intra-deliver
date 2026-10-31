import { Info } from 'lucide-react';
import type { ComponentProps, ReactNode } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

/** 名前の横の注(name = 項目の名前。読み上げの名前「<name>の注」に使う。text = 注の文) */
type DetailNote = { name: string; text: string };

/** 注をボタンにして、マウスを使わない人もフォーカスで読めるようにする(docs の 設計/画面の意匠/README.md の方針 9(e)) */
function DetailNoteButton({ note }: { note: DetailNote }) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    aria-label={`${note.name}の注`}
                    className={cn(
                        'inline-flex rounded-full text-muted-foreground outline-none hover:text-foreground',
                        'focus-visible:ring-[3px] focus-visible:ring-ring/50',
                    )}
                >
                    <Info className="size-3.5" aria-hidden="true" />
                </button>
            </TooltipTrigger>
            <TooltipContent>{note.text}</TooltipContent>
        </Tooltip>
    );
}

/**
 * 詳細の「名前の下に値」の 1 項目(dl の中に置く)。描き方を 1 か所にして、使う画面どうしの見た目を揃える
 * (docs の 設計/画面の意匠/README.md の方針 9(e))。注の文は値の後ろの見えない dd にも置く(読み上げで名前 → 値 → 注の順に届く)。
 */
export function DetailItem({
    name,
    note,
    className,
    valueClassName,
    valueProps,
    children,
}: {
    name: ReactNode;
    note?: DetailNote;
    className?: string;
    valueClassName?: string;
    valueProps?: Pick<
        ComponentProps<'dd'>,
        'aria-invalid' | 'aria-describedby'
    >;
    children: ReactNode;
}) {
    return (
        <div
            className={cn(
                'flex min-w-0 flex-col gap-1 border-b pb-3',
                className,
            )}
        >
            <dt className="flex items-center gap-1 text-xs font-medium text-muted-foreground">
                {name}
                {note !== undefined && <DetailNoteButton note={note} />}
            </dt>
            <dd {...valueProps} className={cn('min-w-0', valueClassName)}>
                {children}
            </dd>
            {note !== undefined && <dd className="sr-only">{note.text}</dd>}
        </div>
    );
}
