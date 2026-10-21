<?php

namespace Tests\Architecture\Policy;

/**
 * 参照の実在の照合(tests/Architecture/DocsReferencesTest.php)の intra-deliver 固有の値。docs の 設計/コード構成/検査/README.md 6.2。
 */
final class DocsReferences
{
    /** docs の場所を渡す変数(環境変数か、web の根の .env) */
    public const DOCS_ROOT_VARIABLE = 'DOCS_ROOT';

    /** web が git の本体の作業木のときの docs の場所(web の根からの相対パス) */
    public const MAIN_CHECKOUT_DOCS = '../docs';

    /** docs の根の直下の、web から指さないディレクトリ(経緯の文書。docs の 設計/コード構成/否決案と指針.md 8 節) */
    public const HISTORY_DIRECTORY = 'work';

    /** 照合の対象と除外を読む台帳⑧の検査名(参照と経緯の語。経緯の語の照合も使う) */
    public const EXCLUSION_CHECK = 'R1';
}
