<?php

namespace Tests\Support;

use App\Http\Middleware\Authz\AuthorizeRoute;
use App\Models\Authz\AuthzResource;
use App\Queries\Authz\RouteResources;
use Closure;
use Illuminate\Routing\RedirectController;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Routing\ViewController;
use Inertia\Controller as InertiaController;
use Tests\Architecture\Policy\Modules;

/**
 * アプリのルートの規則の判定(docs の 設計/メニュー/認可との結び付け.md 2.3 の検査 1・2)。どれも、受けたルートのうち規則に合わないものの
 * URI(重複は名前)を返す。検査 1 = AppRoutesTest、検査 2 = AuthorizationSeedersTest が使い、既定と違う設定で起動したテストも同じ判定を使う
 * (判定を 1 か所に置き、写しを作らない)。
 */
trait FindsAppRouteViolations
{
    /**
     * 初期データを入れた後に、名前から決まる URI の service のリソースが無いか、そのリソースグループ ID と親のリソースグループ ID が
     * 名前から決まるもの(2.3 の表)でないルートの URI(検査 2)。
     *
     * @param  list<RoutingRoute>  $routes
     * @return list<string>
     */
    private function routesWithoutTheirResource(array $routes): array
    {
        $routeResources = $this->routeResources();

        return array_values(array_map(fn (RoutingRoute $route) => $route->uri(), array_filter($routes, function (RoutingRoute $route) use ($routeResources) {
            $name = (string) $route->getName();
            $group = AuthzResource::query()->with('authzResourceGroup.parent')->where('uri', $routeResources->uriOf($name))->where('resource_type', 'service')->first()?->authzResourceGroup;

            return [$group?->code, $group?->parent?->code] !== [$routeResources->resourceGroupCodeOf($name), $routeResources->parentResourceGroupCodeOf($name)];
        })));
    }

    /**
     * ルータが実際に掛けるミドルウェアに AuthorizeRoute を持たないルートの URI。
     *
     * @param  list<RoutingRoute>  $routes
     * @return list<string>
     */
    private function routesWithoutAuthorization(array $routes): array
    {
        $router = app(Router::class);

        return array_values(array_map(
            fn (RoutingRoute $route) => $route->uri(),
            array_filter($routes, fn (RoutingRoute $route) => ! in_array(AuthorizeRoute::class, $router->gatherRouteMiddleware($route), true)),
        ));
    }

    /**
     * 処理がクロージャのルートの URI。
     *
     * @param  list<RoutingRoute>  $routes
     * @return list<string>
     */
    private function routesHandledByClosure(array $routes): array
    {
        return array_values(array_map(
            fn (RoutingRoute $route) => $route->uri(),
            array_filter($routes, fn (RoutingRoute $route) => $route->getAction('uses') instanceof Closure),
        ));
    }

    /**
     * 名前が無いか、名前の規則(形・長さ・ほかの名前の接頭辞にならない)に合わないか、名前の先頭の区切りがそのルートの処理の持ち主のモジュールでない
     * ルートの URI。
     *
     * @param  list<RoutingRoute>  $routes
     * @return list<string>
     */
    private function routesAgainstTheNameRules(array $routes): array
    {
        return array_values(array_map(
            fn (RoutingRoute $route) => $route->uri(),
            array_filter($routes, fn (RoutingRoute $route) => $route->getName() === null
                || ! $this->routeResources()->followsNameRules($route->getName())
                || ! in_array(explode('.', $route->getName())[0], $this->namePrefixesFor($route), true)),
        ));
    }

    /**
     * ルートの名前の先頭に置ける区切り(持ち主のモジュール名の小文字)。コントローラは名前空間 App\Http\Controllers\{Web,Api}\<M>\ の <M>、
     * Route::inertia はページ名(ルートの defaults の component)<M>/… の <M>、Route::redirect・Route::view は宣言したモジュールのどれでも。
     * 宣言に無い <M> と、どれにも当たらない処理は置ける区切りが無い(違反)。
     *
     * @return list<string>
     */
    private function namePrefixesFor(RoutingRoute $route): array
    {
        $controller = ltrim((string) $route->getControllerClass(), '\\');
        $component = $route->defaults['component'] ?? null;

        $modules = match (true) {
            $controller === InertiaController::class && is_string($component) => [explode('/', $component)[0]],
            in_array($controller, [RedirectController::class, ViewController::class], true) => Modules::names(),
            preg_match('/\AApp\\\\Http\\\\Controllers\\\\(?:Web|Api)\\\\(\w+)\\\\/', $controller, $matches) === 1 => [$matches[1]],
            default => [],
        };

        return array_values(array_map(strtolower(...), array_intersect($modules, Modules::names())));
    }

    /**
     * 2 つ以上のルートに付いた名前。
     *
     * @param  list<RoutingRoute>  $routes
     * @return list<string>
     */
    private function duplicatedNames(array $routes): array
    {
        $counts = array_count_values(array_filter(array_map(fn (RoutingRoute $route) => $route->getName(), $routes)));

        return array_map(strval(...), array_keys(array_filter($counts, fn (int $count) => $count > 1)));
    }

    /**
     * ホストの条件かスキームの条件(http・https だけ)を持つルートの URI。
     *
     * @param  list<RoutingRoute>  $routes
     * @return list<string>
     */
    private function routesWithHostOrSchemeCondition(array $routes): array
    {
        return array_values(array_map(
            fn (RoutingRoute $route) => $route->uri(),
            array_filter($routes, fn (RoutingRoute $route) => $route->getDomain() !== null || $route->httpOnly() || $route->secure()),
        ));
    }

    /**
     * 引数に明示の結び付け(Route::bind・Route::model)があるルートの URI。
     *
     * @param  list<RoutingRoute>  $routes
     * @return list<string>
     */
    private function routesWithExplicitBinding(array $routes): array
    {
        $router = app(Router::class);

        return array_values(array_map(
            fn (RoutingRoute $route) => $route->uri(),
            array_filter($routes, fn (RoutingRoute $route) => array_filter(
                $route->parameterNames(),
                fn (string $name) => $router->getBindingCallback($name) !== null,
            ) !== []),
        ));
    }

    private function routeResources(): RouteResources
    {
        return app(RouteResources::class);
    }
}
