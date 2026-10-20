<?php

/*
 * 検査基盤の値のうち、ツールの設定ファイルに書けないもの(Policy)。
 *
 * 数値の正本はこのファイルと各ツールの設定ファイル(phpstan.neon・pint.json・phpunit.xml・.gitleaks*.toml)だけとする
 * (文書の表は人が読むためのもので、照合の相手にしない)。
 * 台帳(検査の除外 ⑧・コード構成 ①②)は config/guardrails_allow.php、直接依存 ⑤ は config/guardrails_ledger.php。
 * 流用元 = chatpinet_2 の config/guardrails.php(判定は docs の 設計/chatpinet_2からの流用.md 3 節)。
 */

return [
    // 秘匿情報検査のベースライン(アプリのルート起点。台帳⑧ #11 に登録する)
    'secrets_baseline' => '.gitleaks-baseline.json',

    // 検査の成果物の置き場(アプリのルート起点。git 管理外。junit・取得したバイナリ・自己証明の一時 fixture)
    'artifacts_dir' => 'build',

    // テスト DB の名前が終わるべき接尾辞(#1。tests/TestCase.php がテスト DB 以外の初期化を止めるのに使う)
    'test_db_suffix' => '_test',
];
