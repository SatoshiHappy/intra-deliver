import type { ComponentProps, ReactNode } from 'react';
import { cn } from '@/lib/utils';

// 隙の値は class の全体を書く(Tailwind が数値からは class を作れないため)
const GAPS = { 4: 'gap-4', 5: 'gap-5' } as const;

/**
 * 面を 2 つ横に並べるフォーム(項目のフォームと業務モジュールの編集の「基本 / 属性」)の格子(docs の 設計/画面の意匠/README.md の方針 9(c))。
 * xl 以上で、左の面の幅を「面の中の入力の並びの最大の幅 + 面の余白と枠」(ただし本文の 3 分の 2 まで)、右の面を残りにして、2 つの面で本文の幅を埋める。
 * 45rem = 面の中の並びの最大の幅 max-w-2xl(42rem)+ p-5 の左右(2.5rem)+ 枠(2px)を切り上げた値。入力の並びは左から今の幅のまま、余白は右の面の中に収まる。
 * xl 未満は 1 列。2 つの面とも inner = max-w-2xl で描く(FormSurface)。
 */
export const TWO_SURFACES_GRID =
    'grid items-start gap-6 xl:grid-cols-[minmax(0,min(45rem,66%))_1fr]';

type SurfaceProps = ComponentProps<'section'> & {
    /** 面の中の入力の並びの最大の幅(業務データの登録・編集 = max-w-5xl、設定の画面のフォーム = max-w-2xl) */
    inner?: 'max-w-2xl' | 'max-w-5xl';
    /** 入力の並びの縦の隙(4 = gap-4、5 = gap-5) */
    gap?: keyof typeof GAPS;
};

/**
 * ページのフォームの面(枠)。枠は本文の幅いっぱい(骨格の上限まで)で、中の入力の並びだけを最大の幅の箱で包んで左に置く
 * (docs の 設計/画面の意匠/README.md の方針 9(c))。面が 2 つ以上あるフォームでも、どの面もこの部品で描く。
 */
export function FormSurface({
    inner = 'max-w-5xl',
    gap = 5,
    className,
    children,
    ...props
}: SurfaceProps) {
    return (
        <section
            className={cn(
                'min-w-0 rounded-xl border bg-card p-5 text-card-foreground',
                className,
            )}
            {...props}
        >
            <div className={cn('flex flex-col', GAPS[gap], inner)}>
                {children}
            </div>
        </section>
    );
}

/**
 * ページのフォームのボタンの列。面の右端に揃え、左から「キャンセル → 主ボタン」(主ボタンが右端。docs の 設計/画面の意匠/README.md の方針 11)。
 * 並びは DOM の順と同じにする(呼び手が子をその順に置く。flex-row-reverse で見た目だけ入れ替えない)。
 */
export function FormActions({ children }: { children: ReactNode }) {
    return (
        <div className="flex items-center justify-end gap-2">{children}</div>
    );
}
