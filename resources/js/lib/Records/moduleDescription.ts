/**
 * 業務の一覧の見出しの横の「?」に入れる業務モジュールの説明。空なら null(「?」を出さない。
 * docs の 設計/画面の意匠/README.md 方針 9(d)、設計/業務データの入れ物/画面/業務の画面.md の 2.1)。
 */
export function moduleDescriptionHelp(
    description: string | null,
): string | null {
    return description === null || description === '' ? null : description;
}
