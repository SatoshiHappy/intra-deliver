/** ページの番号を出す範囲(今のページの前後。最初と最後はいつも出し、間は「…」) */
const PAGE_WINDOW = 2;

/**
 * 出すページの番号(最初・最後・今の前後 PAGE_WINDOW。飛んだところは null =「…」)。一覧のページ送りの共通の部品が使う。
 */
export function pageItems(
    currentPage: number,
    lastPage: number,
): (number | null)[] {
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
