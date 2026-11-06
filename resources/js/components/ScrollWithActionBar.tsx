import type { ReactNode } from 'react';
import { ActionBar } from '@/components/ActionBar';
import { useContentBelow } from '@/hooks/use-content-below';
import { cn } from '@/lib/utils';

/**
 * 中でスクロールする欄の中身と、欄の下端に固定した帯(docs の 設計/画面の意匠/README.md の方針 14)。帯が無い(bar = null)ときは中身だけ。
 */
export function ScrollWithActionBar({
    bar,
    className,
    children,
}: {
    bar: ReactNode;
    className?: string;
    children: ReactNode;
}) {
    const { scrollRef, contentRef, isBelow } = useContentBelow();

    return (
        <>
            {/* relative: 中の絶対配置の要素(隠した入力など)も欄の中に収め、ページを伸ばさない */}
            <div
                ref={scrollRef}
                className="relative min-h-0 flex-1 overflow-y-auto"
            >
                <div
                    ref={contentRef}
                    className={cn('flex flex-col gap-4 p-5 pb-6', className)}
                >
                    {children}
                </div>
            </div>
            {bar !== null && <ActionBar raised={isBelow}>{bar}</ActionBar>}
        </>
    );
}
