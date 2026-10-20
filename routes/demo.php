<?php

use App\Http\Controllers\Web\Demo\SwitchUserController;
use Illuminate\Support\Facades\Route;

// デモユーザー切替のルート(docs の 設計/デモユーザー切替/デモの取り付け.md 6.3)。オンのときだけ App\Providers\DemoServiceProvider が、
// web グループとルートの認可(AuthorizeRoute)を付けたグループで読み込む。名前・処理の規則は routes/web.php と同じ(検査も同じ)。
// リソースとポリシーは database/seeders/DemoSeeder.php が作る。
Route::post('/demo/switch-user', SwitchUserController::class)->name('demo.switch-user');
