import { CircleQuestionMark } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

/**
 * 面(カード)の見出しの説明(docs の 設計/画面の意匠/README.md 方針 9(d) と同じ作り: 見出しの横の「?」のアイコン + Tooltip。
 * マウスオーバーとキーボードのフォーカスで出す)。見出しの下に常に出していた説明の置き場。
 * 「?」は見出しの要素(h2 など)の外のボタンで(面の名前 = aria-labelledby の見出しを変えない)、呼ぶ側が見出しと横に並べる外側の要素で包む。
 * 読み上げの名前は「<見出し>の説明」。説明文は読み上げ用の見えない要素にも置く(マウスを使わない読み上げにも届くように)。
 * label = 見出しの文、children = 説明の文。
 */
export function HeadingHelp({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <>
            <Tooltip>
                <TooltipTrigger asChild>
                    <button
                        type="button"
                        aria-label={`${label}の説明`}
                        className={cn(
                            'inline-flex rounded-full text-muted-foreground outline-none hover:text-foreground',
                            'focus-visible:ring-[3px] focus-visible:ring-ring/50',
                        )}
                    >
                        <CircleQuestionMark
                            className="size-4"
                            aria-hidden="true"
                        />
                    </button>
                </TooltipTrigger>
                <TooltipContent className="max-w-sm">{children}</TooltipContent>
            </Tooltip>
            <span className="sr-only">{children}</span>
        </>
    );
}
