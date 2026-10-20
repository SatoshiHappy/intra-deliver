/**
 * 文字数(符号位置の数)。サーバーの mb_strlen と同じ数え方にする(String の length は UTF-16 の単位で、サロゲートペアの文字を 2 と数える)。
 */
export function countCharacters(text: string): number {
    return Array.from(text).length;
}
