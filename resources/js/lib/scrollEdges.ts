/**
 * スクロールする欄の端の判定(docs の 設計/画面の意匠/README.md の方針 14: 帯の下に中身が続くときだけ帯から上へ影を出す)。
 */

/** 端とみなす誤差(px。拡大率で scrollTop が小数になり、末尾でも 1 未満の差が残るため) */
const EDGE_TOLERANCE = 1;

/**
 * 見えている範囲の下に、まだ中身が続くか(スクロールして下へ進める)。
 */
export function hasContentBelow(
    scrollTop: number,
    clientHeight: number,
    scrollHeight: number,
): boolean {
    return scrollHeight - (scrollTop + clientHeight) > EDGE_TOLERANCE;
}

/**
 * 画面の高さに合わせた要素の高さ(要素の文書の上端から画面の下端まで。下の余白を引き、最小の高さより低くしない)。
 */
export function fillHeightOf(
    viewportHeight: number,
    documentTop: number,
    bottomGap: number,
    minHeight: number,
): number {
    return Math.max(
        minHeight,
        Math.floor(viewportHeight - documentTop - bottomGap),
    );
}
