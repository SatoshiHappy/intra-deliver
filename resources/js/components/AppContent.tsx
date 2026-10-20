import type { ReactNode } from 'react';
import { SidebarInset } from '@/components/ui/sidebar';

/**
 * 本文(docs の 設計/画面の意匠/骨格とヘッダー.md 2 節)。地は --muted(ヘッダーとサイドバーの外)、中は上限 1600px で中央に寄せ、
 * flex-1 flex-col でページに高さを伝える。ページの余白は各ページが持つ。SidebarInset は横並びの flex の子なので、min-w-0 が無いと
 * 中身の最小幅でページごと横に広がる(広い表は表の入れ物の中でスクロールさせる)。
 */
export function AppContent({ children }: { children: ReactNode }) {
    return (
        <SidebarInset className="min-w-0 bg-muted">
            <div className="mx-auto flex w-full max-w-[1600px] min-w-0 flex-1 flex-col">
                {children}
            </div>
        </SidebarInset>
    );
}
