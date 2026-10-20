---
paths:
    - 'database/**'
---

# DB 定義(database/)

- クラスの外に処理を書かない(マイグレーションの `return new class …;` だけ書ける)
- 依存してよい先: `migrations` = 外部だけ / `factories` = 永続化・列挙・共有・外部(require-dev を含む)/ `seeders` = 業務処理・永続化・列挙・共有・外部
- 本番の初期データは seeder が業務処理を呼んで作る(業務処理で止める規則を守るため)。seeder から factory・Faker を使えない(本番の `--no-dev` に無い。PHPStan の自作規則 `forbiddenExpressionType` が、呼び出しの結果の型が factory か `Faker\Generator` のものを止める)
- 副作用の照合の対象外(DB の初期化)
