<?php

namespace Tests\Architecture\Policy;

/**
 * D 依存のライセンスの検査(scripts/ci/licenses.php)の Policy = 許可するライセンスと、除外を登録する台帳⑧のキー。
 *
 * 方針の根拠 = docs の ADR 0009(AGPL / GPL のコードは入れない)。判定本体は Engine の LicenseAudit。
 */
final class Licenses
{
    /** 台帳⑧ で依存の除外(kind = package)を登録するキー(D 依存 = #7) */
    public const LEDGER_CHECK = '#7';

    /**
     * 許可する SPDX 識別子(大文字小文字を区別しない)。式は、どれか 1 つが許可なら許可(`A OR B`)・すべて許可なら許可(`A AND B`)。
     * 基準 = 実物の依存の判定に要る SPDX だけ。足すときは、検査が失敗した時点で理由を書いて足す。
     * 許可しない = GPL・AGPL・LGPL・SSPL などのコピーレフトと、不明(license 欄なし・UNKNOWN・UNLICENSED)。
     * - MPL-2.0 = ファイル単位の弱いコピーレフト。改変しない推移的依存(lightningcss)にだけ現れる
     * - OFL-1.1 = 書体の許諾で、アプリのコードには及ばない(@fontsource の書体)
     */
    public const ALLOWED = [
        'MIT',
        'Apache-2.0',
        'ISC',
        'BSD-3-Clause',
        '0BSD',
        'MPL-2.0',
        'OFL-1.1',
    ];
}
