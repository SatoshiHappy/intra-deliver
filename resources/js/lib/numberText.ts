/** 整数の形(符号つき。業務処理の入口の整数の文字列と同じ = docs の 設計/業務データの入れ物/項目と規則/README.md の 2 節) */
export const INTEGER_TEXT = /^-?[0-9]+$/;

/** 小数の形(同じ 2 節の小数の文字列) */
export const DECIMAL_TEXT = /^-?[0-9]+(\.[0-9]+)?$/;

/** 半角にそろえる全角の文字 */
const FULL_WIDTH_NUMBER = /[\uFF10-\uFF19\uFF0D\uFF0E]/g;

/** 全角と半角の符号位置の差 */
const FULL_WIDTH_OFFSET = 0xfee0;

/**
 * 数の欄の文字を、送る前と画面で読む前の形にする(docs の 設計/画面の意匠/README.md の方針 9 の (g))。日本語の入力の全角のままでも
 * 数として通すため。
 */
export function normalizeNumberText(text: string): string {
    return text
        .trim()
        .replace(FULL_WIDTH_NUMBER, (character) =>
            String.fromCharCode(character.charCodeAt(0) - FULL_WIDTH_OFFSET),
        );
}
