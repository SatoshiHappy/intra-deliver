import { Button } from '@/components/ui/button';

type Props = {
    currentPage: number;
    lastPage: number;
    total: number;
    onPage: (page: number) => void;
};

/**
 * 通知の一覧のページ送り: 前へ・次へ・「現在 / 最後」・「全 N 件」(20 件ずつ)。
 */
export function NoticePagination({
    currentPage,
    lastPage,
    total,
    onPage,
}: Props) {
    return (
        <nav aria-label="ページ" className="flex items-center gap-2">
            <Button
                variant="outline"
                size="sm"
                disabled={currentPage <= 1}
                onClick={() => onPage(currentPage - 1)}
            >
                前へ
            </Button>
            <span className="font-num text-sm tabular-nums">
                {currentPage} / {Math.max(lastPage, 1)}
            </span>
            <Button
                variant="outline"
                size="sm"
                disabled={currentPage >= lastPage}
                onClick={() => onPage(currentPage + 1)}
            >
                次へ
            </Button>
            <span className="ml-2 text-sm text-muted-foreground">
                全 <span className="font-num tabular-nums">{total}</span> 件
            </span>
        </nav>
    );
}
