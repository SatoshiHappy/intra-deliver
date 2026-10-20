<?php

namespace App\Providers;

use App\Http\Middleware\Authz\AuthorizeRoute;
use App\Queries\Account\UserMenuSections;
use App\Queries\Demo\LeadHomeSection;
use App\Queries\Demo\PeopleHomeSection;
use App\Queries\Demo\SwitchUserMenuSection;
use App\Queries\Menu\Api\HomeSections;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * デモユーザー切替(モジュール Demo)の取り付け(docs の 設計/デモユーザー切替/デモの取り付け.md 6.3)。
 *
 * 設定 demo.enabled が true のときだけ、利用者メニューの節の実装をタグ {@see UserMenuSections::TAG} に、ホームの節の実装
 * (あいさつ欄の一文と役割カード)をタグ {@see HomeSections::TAG} にこの順で登録し(register)、
 * 切替のルート(routes/demo.php)を読み込む(boot)。オフなら何も登録しない。設定はプロバイダの登録より前に読み込まれている。
 */
class DemoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->enabled()) {
            $this->app->tag([SwitchUserMenuSection::class], UserMenuSections::TAG);
            // ホームの節(AppServiceProvider の後に登録されるので、main の節の後に並ぶ = docs の 設計/メニュー/ホーム.md 6.2)
            $this->app->tag([LeadHomeSection::class, PeopleHomeSection::class], HomeSections::TAG);
        }
    }

    public function boot(): void
    {
        if ($this->enabled()) {
            $this->loadSwitchUserRoutes();
        }
    }

    /**
     * routes/demo.php を、アプリのルートと同じく web グループとルートの認可を付けたグループで読み込む(グローバルナビと account の共有は
     * 付けない = 切替のルートは画面を描かない)。ルートがキャッシュされているときは読み込まない(ServiceProvider::loadRoutesFrom と同じ扱い)。
     * 設定を見ない(呼ぶのは boot と、オフでルートが残ったときを再現するテスト)。
     */
    public function loadSwitchUserRoutes(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        Route::middleware(['web', AuthorizeRoute::class])->group(base_path('routes/demo.php'));
    }

    private function enabled(): bool
    {
        return config('demo.enabled') === true;
    }
}
