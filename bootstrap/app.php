<?php

use App\Http\Middleware\Account\LogOutIneffectiveUser;
use App\Http\Middleware\Account\ShareCurrentAccount;
use App\Http\Middleware\Authz\AuthorizeRoute;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\Menu\ShareGlobalNavigation;
use App\Http\Middleware\Notice\ShareNotices;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // アプリのルートはすべて認可を通す(docs の 設計/メニュー/認可との結び付け.md 2.3): routes/web.php を web グループと
        // ルートの認可を付けた 1 つのグループで読み込む(web: に渡すと認可の無いグループで読み込まれるので渡さない)。
        // グローバルナビの共有はルートの認可の後に付ける(docs の 設計/メニュー/表示.md 3.2)。今の利用者と利用者メニューの節の共有は
        // グローバルナビの共有の後(docs の 設計/デモユーザー切替/今の利用者.md 5.7)。通知の共有はそれらの後
        // (設計/ワークフロー/案件の運用/通知.md の 7 節)
        then: function (): void {
            Route::middleware([
                'web',
                AuthorizeRoute::class,
                ShareGlobalNavigation::class,
                ShareCurrentAccount::class,
                ShareNotices::class,
            ])->group(__DIR__.'/../routes/web.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // app は Worker → Tunnel → cloudflared の経路だけを想定し(入口/README.md 5.4、採った方針 11)、どの相手からの X-Forwarded-For・Host・Proto も
        // 信じる(Port は信じない。訪問者が付けた X-Forwarded-Port が URL と 302 の行き先に入るため)。環境を見ずに全環境に掛ける。
        // Caddy の trusted_proxies(docker/app/Caddyfile)は Caddy 側の client_ip の判定に使う範囲で、範囲外の相手の X-Forwarded-* を消す
        // ものではない(公式文書)ので、ここの守りとして頼らない。app に直接届く経路を作らないこと(ホストにポートを公開しない = 構成.md 3.1)で守る
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // 今日有効でない今の利用者をガードから外すのは、セッションを始めた後、ルートの認可と共有より前
        // (web グループの追加の先頭。docs の 設計/デモユーザー切替/今の利用者.md 5.4)
        $middleware->web(append: [
            LogOutIneffectiveUser::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // 要求の本文の上限超えは、セッションより前の大域のミドルウェアで止まるので元の画面へ戻せない。状態 413・本文なしで返し、
        // 画面が状態を見て知らせる(docs の 設計/ワークフロー/申請の周辺/添付/README.md の 4 節)
        $exceptions->render(fn (PostTooLargeException $exception) => response()->noContent(413));
    })->create();
