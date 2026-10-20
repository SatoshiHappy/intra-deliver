---
paths:
    - 'resources/js/pages/**'
    - 'resources/views/**'
---

# 画面のページとテンプレート

- ページは `resources/js/pages/<M>/` に置く(`pages/` の直下は宣言したモジュールのディレクトリだけ。ファイルは置かない)。拡張子は `.tsx`
- 入口が描くページのファイルが無いと、PHPUnit の環境では描画が失敗する(`config/inertia.php` の `pages.ensure_pages_exist`)。入口ごとに応答を検証する Feature テストを書く
- `resources/views/` に置けるのは Inertia の根のテンプレート `app.blade.php` だけ(Blade は静的解析しない)
