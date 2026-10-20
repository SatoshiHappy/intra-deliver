---
paths:
    - 'app/Providers/**'
    - 'bootstrap/**'
---

# 起動(Providers・bootstrap)

- 束縛と登録は `App\Providers` で行う。依存の逆転(前のモジュールの interface を後ろのモジュールが実装する)の登録もここ
- `App\Providers` は `App\` のすべての層と外部に依存できる。ほかの層から `App\Providers` へは依存できない
- `bootstrap/` は配線のファイル: 依存してよいのは入口・共有・起動・コマンド・外部(DB を除く)。副作用は使えない
- `register`・`boot` は起動時に 1 回だけ走る前提で書く(要求ごとの状態をシングルトンや `static` に置かない)
