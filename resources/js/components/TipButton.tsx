import { CircleQuestionMark, Info } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

/** help = 見出し・欄の横の「?」(説明)、note = 項目の名前の横の「i」(注) */
type TipKind = 'help' | 'note';

const KINDS = {
    help: { Icon: CircleQuestionMark, iconClass: 'size-4', suffix: '説明' },
    note: { Icon: Info, iconClass: 'size-3.5', suffix: '注' },
} as const satisfies Record<TipKind, object>;

/**
 * 名前の横の「?」と「i」のボタン + Tooltip の描き方の 1 か所(docs の 設計/画面の意匠/README.md 方針 9(d)・9(e))。
 * ボタンの読み上げは Radix の Tooltip に任せ、文をほかの所でも読ませたい呼び手が自分で写しを置く。
 */
export function TipButton({
    kind,
    name,
    children,
}: {
    kind: TipKind;
    name: string;
    children: ReactNode;
}) {
    const { Icon, iconClass, suffix } = KINDS[kind];

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    aria-label={`${name}の${suffix}`}
                    className={cn(
                        'inline-flex rounded-full text-muted-foreground outline-none hover:text-foreground',
                        'focus-visible:ring-[3px] focus-visible:ring-ring/50',
                    )}
                >
                    <Icon className={iconClass} aria-hidden="true" />
                </button>
            </TooltipTrigger>
            {/* 管理者が書く複数行・4000 字までの説明も入るので、改行を保ち、高さを画面の 6 割までにしてはみ出させない */}
            <TooltipContent className="max-h-[60vh] max-w-sm overflow-y-auto whitespace-pre-line">
                {children}
            </TooltipContent>
        </Tooltip>
    );
}
