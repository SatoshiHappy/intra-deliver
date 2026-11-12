/**
 * 業務データの項目の入力欄の id(ラベル・誤りの文言・空にした理由の 1 行の id はこの id から作る)。
 */
export function recordFieldId(code: string): string {
    return `record-field-${code}`;
}
