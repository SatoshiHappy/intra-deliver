---
paths:
    - 'app/Http/**'
    - 'routes/**'
---

# 入口(Http・routes)

- 入口はトランザクションを持たず、DB とモデルに依存しない。識別子を業務処理・読み取りに渡し、結果を描く(ルートモデル結合は使えない)
- 主体(選択中のデモユーザー)は入口が決めて識別子を渡し、役割の判定は業務処理・読み取りが行う
- 入口(共通)= `App\Http\Middleware` の直下のミドルウェアと `Controllers\Controller`。どのモジュールにも依存できない
- `routes/` は配線のファイル: 依存してよいのは入口・共有・起動・コマンド・外部(DB を除く)。副作用は使えない。定期の処理は `routes/console.php` のスケジュールからコマンドのクラスを名指す(業務処理は名指せない)
- Inertia のページ名は `<M>/…`(`resources/js/pages/<M>/<名前>.tsx` と一致させる)。名前に `.` を使わない(サーバー側の検査は通るが、画面側で解決できない)
- ルートの名前は `<モジュール名の小文字>.<区切り>(.<区切り>…)`(区切りは `[a-z0-9_-]+`)で 241 文字以内。ほかのルートの名前の接頭辞(`.` で切った先頭の部分)にしない(例: `menu.admin` と `menu.admin.users` を両方ルートにしない)。アプリのルートの中で一意にする(認可は名前だけで決まるので、同じ名前のルートは許可を共有する)。先頭の区切りは、そのルートの処理が属するモジュール(コントローラは名前空間 `Controllers\Web\<M>`・`Controllers\Api\<M>` の `<M>`、`Route::inertia` はページ名 `<M>/…` の `<M>`。`Route::redirect`・`Route::view` は宣言したモジュールのどれか)。検査 = `tests/Feature/Routing/AppRoutesTest.php`
- ルートにホストの条件(`Route::domain`)とスキームの条件(`http`・`https` だけ)を付けない。メニューの表示判定の照合がホストとスキームを見ないため。同じ検査が止める
- ルートの引数に明示の結び付け(`Route::bind`・`Route::model`)を使わない(起動 `App\Providers` に書くものを含む)。引数の結び付けはルートの認可より先に走り、行があるかで 404 と 403 が分かれるため。同じ検査が止める
- ルートの処理にクロージャを使わない(コントローラか `Route::inertia`・`Route::redirect`・`Route::view`)。ルートのキャッシュの後はクロージャが直列化され、アプリのルートと判定できないため。同じ検査が止める
- `routes/web.php` は、起動の配線(`bootstrap/app.php` の `withRouting` の `then`)が `web` グループと `App\Http\Middleware\Authz\AuthorizeRoute` を付けて読み込む。`web:` に渡さない。ルートで `withoutMiddleware(AuthorizeRoute::class)` を使わない(同じ検査が止める)
- ルートを足したら、そのモジュールの seeder に、ルートのリソース(業務処理 `CreateRouteResource`)とポリシーを足す。名前の接頭辞のリソースグループがまだ無ければ、seeder が表示名つきで作る(`CreateRoutePrefixResourceGroup`)。リソースや許可の無いルートは 403 になる
