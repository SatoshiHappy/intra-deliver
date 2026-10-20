import type { ReactNode } from 'react';
import { TableBody, TableHeader } from '@/components/ui/table';

/**
 * 格子の入れ物。見出しの行は上に、先頭の列は左に止める(画面の高さに収まる範囲でスクロール)ため、Table の入れ物を使わず
 * ページ側の div(overflow-auto)に table を置く(docs の 認可設定の画面.md 2.3。縦の罫線なし = 行の線は各セルの border-b)。
 */
export function GridFrame({
    head,
    children,
}: {
    head: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="max-h-[70vh] overflow-auto">
            <table className="w-full caption-bottom border-separate border-spacing-0 text-sm">
                <TableHeader className="sticky top-0 z-20">{head}</TableHeader>
                <TableBody>{children}</TableBody>
            </table>
        </div>
    );
}
