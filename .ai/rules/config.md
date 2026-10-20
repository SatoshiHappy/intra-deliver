---
paths:
    - 'config/**'
    - 'composer.json'
    - 'package.json'
---

# 設定と台帳

- `config/` は配線のファイル: 依存してよいのは入口・共有・起動・コマンド・外部(DB を除く)。モデルを名指せない(例 = 認証の利用者のモデル)。副作用は使えない
- 台帳は 3 つ: ⑧ 検査の除外と ①② コード構成 = `config/guardrails_allow.php`(実体 ⊆ 台帳)、⑤ 直接依存 = `config/guardrails_ledger.php`(双方向)。依存を足す・消すときは同じ変更で台帳⑤を直す
- 台帳①に基底ディレクトリを足すときは層の規則(`tests/Architecture/Policy/LayerRules.php`)が要る。台帳②は上限(`tests/Architecture/Policy/SideEffects.php`)の中だけ広げられる
- `php artisan config:cache` を実行しない(設定キャッシュがあるとテストが止まる)
