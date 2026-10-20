import { Button } from '@/components/ui/button';

/** ページの番号を出す範囲(今のページの前後。最初と最後はいつも出し、間は「…」) */
const PAGE_WINDOW = 2;

/**
 * 出すページの番号(最初・最後・今の前後 PAGE_WINDOW。飛んだところは null =「…」)。
 */
function pageItems(currentPage: number, lastPage: number): (number | null)[] {
    const items: (number | null)[] = [];

    for (let page = 1; page <= lastPage; page += 1) {
        const isShown =
            page === 1 ||
            page === lastPage ||
            Math.abs(page - currentPage) <= PAGE_WINDOW;

        if (isShown) {
            items.push(page);
        } else if (items[items.length - 1] !== null) {
            items.push(null);
        }
    }

    return items;
}

/**
 * 案件一覧のページング(docs の 設計/ワークフロー/画面.md 4 節 = 業務データの入れ物の一覧と同じ形: 前へ・次へ・番号・「全 N 件」)。
 */
export function CasePagination({
    currentPage,
    lastPage,
    total,
    onPage,
}: {
    currentPage: number;
    lastPage: number;
    total: number;
    onPage: (page: number) => void;
}) {
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
            {pageItems(currentPage, lastPage).map((page, position) =>
                page === null ? (
                    <span
                        key={`gap-${position}`}
                        aria-hidden="true"
                        className="px-1 text-muted-foreground"
                    >
                        …
                    </span>
                ) : (
                    <Button
                        key={page}
                        variant={page === currentPage ? 'secondary' : 'ghost'}
                        size="sm"
                        aria-current={page === currentPage ? 'page' : undefined}
                        onClick={() => onPage(page)}
                        className="font-num tabular-nums"
                    >
                        {page}
                    </Button>
                ),
            )}
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
