<?php

use App\Http\Controllers\Web\Demo\SwitchUserController;
use App\Support\RoutePlacement;
use Illuminate\Support\Facades\Route;

// デモユーザー切替のルート(docs の 設計/デモユーザー切替/デモの取り付け.md 6.3)。オンのときだけ App\Providers\DemoServiceProvider が、
// web グループとルートの認可(AuthorizeRoute)を付けたグループで読み込む。名前・処理の規則は routes/web.php と同じ(検査も同じ)。
// リソースとポリシーは database/seeders/DemoSeeder.php が作る。置き場は設定不可のセット(使えるかは設定と候補のデモユーザーで決まる =
// docs の 設計/デモユーザー切替/デモの取り付け.md 6.3 の初期データ)。
Route::metadata(RoutePlacement::UNMANAGED)->group(function (): void {
    Route::post('/demo/switch-user', SwitchUserController::class)->name('demo.switch-user');
});
