---
paths:
    - 'config/**'
    - 'composer.json'
    - 'package.json'
---

# 設定と台帳

- `config/` は配線のファイル: 依存してよいのは入口・共有・起動・コマンド・外部(DB を除く)と、永続化のクラス名だけ。クラス名は、ファイルが返す配列から文字列のキーだけをたどって行き着く値(`'model' => User::class`。入れ子は可)としてだけ書ける(返す配列の外・引数・呼び出し・キーの無い項目・整数のキーや `'0'` のキーを通る位置は不可)。副作用は使えない
- 台帳は 3 つ: ⑧ 検査の除外と ①② コード構成 = `config/guardrails_allow.php`(実体 ⊆ 台帳)、⑤ 直接依存 = `config/guardrails_ledger.php`(双方向)。依存を足す・消すときは同じ変更で台帳⑤を直す
- 台帳①に基底ディレクトリを足すときは層の規則(`tests/Architecture/Policy/LayerRules.php`)が要る。台帳②は上限(`tests/Architecture/Policy/SideEffects.php`)の中だけ広げられる
- `php artisan config:cache` を実行しない(設定キャッシュがあるとテストが止まる)
