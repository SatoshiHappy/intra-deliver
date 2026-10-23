import { Button } from '@/components/ui/button';
import { pageItems } from '@/lib/pagination';

/** 一覧のページの値(読み取りの一覧の currentPage・lastPage・total。行を持つ一覧の props をそのまま渡せる) */
type PageList = { currentPage: number; lastPage: number; total: number };

type Props = {
    list: PageList;
    onPage: (page: number) => void;
    /** 真なら前へ・次へ・番号のボタンを全部押せない(読み込み中など) */
    disabled?: boolean;
};

/**
 * ページの番号のボタン(今のページに aria-current。飛んだところは「…」)。
 */
function PageNumbers({ list, onPage, disabled }: Props) {
    const { currentPage, lastPage } = list;

    return pageItems(currentPage, lastPage).map((page, position) =>
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
                disabled={disabled}
                aria-current={page === currentPage ? 'page' : undefined}
                onClick={() => onPage(page)}
                className="font-num tabular-nums"
            >
                {page}
            </Button>
        ),
    );
}

/**
 * 一覧のページ送り(前へ・ページの番号(最初・最後・今の前後 2。間は「…」)・次へ・「全 N 件」)。案件・通知・業務データの一覧と、
 * 業務データの関連レコードの表が使う(docs の 設計/ワークフロー/画面.md 4 節 = 業務データの入れ物の一覧と同じ形)。
 * 複数のモジュールが使うため直下に置く。1 ページだけでも出す。
 */
export function Pagination({ list, onPage, disabled = false }: Props) {
    const { currentPage, lastPage, total } = list;

    return (
        <nav aria-label="ページ" className="flex items-center gap-2">
            <Button
                variant="outline"
                size="sm"
                disabled={disabled || currentPage <= 1}
                onClick={() => onPage(currentPage - 1)}
            >
                前へ
            </Button>
            <PageNumbers list={list} onPage={onPage} disabled={disabled} />
            <Button
                variant="outline"
                size="sm"
                disabled={disabled || currentPage >= lastPage}
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
