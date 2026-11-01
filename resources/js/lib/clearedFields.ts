/**
 * 写す時に今は使えない値を空にした項目の 1 行(値を写す 3 つの仕組みで同じ文の形。文の正本 = docs の
 * 設計/ワークフロー/申請の周辺/画面.md の 8 節、業務データの画面の複製は「元の申請」を「元のレコード」にしたもの)。
 * 空にした項目が無ければ null(1 行を出さない)。名前の並びはサーバーが決めた並び(画面の欄の順)のまま。
 */
export function clearedFieldsLineOf(
    source: string,
    names: string[],
): string | null {
    return names.length === 0
        ? null
        : `${source}の値のうち、${names.join('、')}は、現在使用できない値を外しました。`;
}
