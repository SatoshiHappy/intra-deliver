/**
 * 表示名か、無ければコード。サーバーは表示名(名称)の行が無いとき null を返し、画面はコード(メニュー ID・組織コード・ユーザーコードなど)を出す
 * (docs の 設計/メニュー/表示.md 3.1 の 7・3.2 のリンク、設計/共通マスタ/検索と組織図.md の「名称が無いときは null(画面がコードを出す)」、
 * 設計/デモユーザー切替/README.md の方針 9)。
 */
export function nameOrCode(target: { code: string; name: string | null }) {
    return target.name ?? target.code;
}
