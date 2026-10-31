import type { ReactNode } from 'react';
import { TableBody, TableHeader } from '@/components/ui/table';

/**
 * 見出しの行と先頭の列を止めるため、Table の入れ物を使わずページ側の div に table を置く。高さから引く 3rem は、ページを最後まで
 * 送ったときに枠の上端をヘッダーに隠さないため(枠の下の余白より少し大きくする。docs の 認可設定の画面.md 2.3)。
 */
export function GridFrame({
    head,
    children,
}: {
    head: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="max-h-[calc(100svh-var(--header-height)-3rem)] overflow-auto">
            <table className="w-full caption-bottom border-separate border-spacing-0 text-sm">
                <TableHeader className="sticky top-0 z-20">{head}</TableHeader>
                <TableBody>{children}</TableBody>
            </table>
        </div>
    );
}
