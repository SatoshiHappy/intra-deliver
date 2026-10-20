<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\RequestPrompt;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Tests\Feature\Ai\Throttle\RequestingByArgumentController;
use Tests\Feature\Ai\Throttle\RequestingController;
use Tests\Feature\Ai\Throttle\UnrelatedController;
use Tests\Support\TransactionalTestCase;

/**
 * AI の依頼の入口の頻度の制限の付け忘れを止める検査(docs の 設計/AI/実行と費用.md 5.2、データと失敗と検査.md 8 節): AI に依頼する業務処理は操作の引数で受ける
 * (コントローラの構築子の依存を推移的にたどって RequestPrompt に届くコントローラは、それだけで違反。同じコントローラの読み直しなどのほかの操作に
 * 制限を求めないため)。操作の引数の型と依存の構築子の引数の型を推移的にたどって RequestPrompt に届くルートは、ルータが掛けるミドルウェアに
 * throttle:ai-requests を持つ。型が静的に決まらない依存(interface の束縛・コンテナから直接引く)は辿らない。
 */
final class AiRequestRoutesTest extends TransactionalTestCase
{
    private const THROTTLE = ThrottleRequests::class.':ai-requests';

    public function test_no_controller_receives_request_prompt_through_its_constructor(): void
    {
        $this->assertSame([], $this->controllersReachingRequestPromptByConstructor(app(Router::class)->getRoutes()->getRoutes()));
    }

    public function test_every_route_whose_action_reaches_request_prompt_has_the_rate_limit(): void
    {
        $this->assertSame([], $this->routesWithoutTheRateLimit(app(Router::class)->getRoutes()->getRoutes()));
    }

    public function test_the_check_finds_a_controller_that_reaches_request_prompt_through_its_constructor_in_two_steps(): void
    {
        $routes = [
            Route::post('/test/ai/by-constructor', [RequestingController::class, 'store'])->middleware('throttle:ai-requests'),
            Route::post('/test/ai/by-argument', [RequestingByArgumentController::class, 'store'])->middleware('throttle:ai-requests'),
        ];

        $this->assertSame([RequestingController::class], $this->controllersReachingRequestPromptByConstructor($routes));
    }

    public function test_the_check_finds_only_the_actions_that_reach_request_prompt_through_two_steps_without_the_rate_limit(): void
    {
        $routes = [
            Route::post('/test/ai/unthrottled', [RequestingByArgumentController::class, 'store']),
            Route::post('/test/ai/throttled', [RequestingByArgumentController::class, 'store'])->middleware('throttle:ai-requests'),
            Route::post('/test/ai/other-limit', [RequestingByArgumentController::class, 'store'])->middleware('throttle:60,1'),
            Route::get('/test/ai/status', [RequestingByArgumentController::class, 'show']),
            Route::get('/test/ai/unrelated', [UnrelatedController::class, 'show']),
        ];

        $this->assertSame(['test/ai/unthrottled', 'test/ai/other-limit'], $this->routesWithoutTheRateLimit($routes));
    }

    /**
     * 構築子の依存を推移的にたどって RequestPrompt に届くコントローラ(重ねない)。
     *
     * @param  array<array-key, RoutingRoute>  $routes
     * @return list<string>
     */
    private function controllersReachingRequestPromptByConstructor(array $routes): array
    {
        $controllers = array_filter(array_map(fn (RoutingRoute $route): ?string => $this->controllerOf($route), $routes));

        return array_values(array_unique(array_filter($controllers, function (string $controller): bool {
            $visited = [];

            return $this->anyReaches([$controller], $visited);
        })));
    }

    /**
     * @param  array<array-key, RoutingRoute>  $routes
     * @return list<string>
     */
    private function routesWithoutTheRateLimit(array $routes): array
    {
        // HTTP のカーネルを作るとミドルウェアの別名(throttle)がルータに渡る(テストでは要求を送るまで作られない)
        app(HttpKernel::class);
        $router = app(Router::class);

        return array_values(array_map(
            fn (RoutingRoute $route): string => $route->uri(),
            array_filter($routes, fn (RoutingRoute $route): bool => $this->actionReachesRequestPrompt($route) && ! in_array(self::THROTTLE, $router->gatherRouteMiddleware($route), true)),
        ));
    }

    /** 操作の引数の依存を推移的にたどって RequestPrompt に届くか */
    private function actionReachesRequestPrompt(RoutingRoute $route): bool
    {
        $controller = $this->controllerOf($route);
        $method = $route->getActionMethod();

        if ($controller === null || ! method_exists($controller, $method)) {
            return false;
        }

        $visited = [];

        return $this->anyReaches($this->classesOf((new ReflectionMethod($controller, $method))->getParameters()), $visited);
    }

    private function controllerOf(RoutingRoute $route): ?string
    {
        $controller = $route->getControllerClass();

        return $controller !== null && class_exists($controller) ? $controller : null;
    }

    /**
     * @param  list<string>  $classes
     * @param  array<string, true>  $visited
     */
    private function anyReaches(array $classes, array &$visited): bool
    {
        foreach ($classes as $class) {
            if ($class === RequestPrompt::class) {
                return true;
            }

            if (isset($visited[$class])) {
                continue;
            }

            $visited[$class] = true;
            $constructor = class_exists($class) ? (new ReflectionClass($class))->getConstructor() : null;

            if ($constructor !== null && $this->anyReaches($this->classesOf($constructor->getParameters()), $visited)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 引数の型のうち、アプリとテストのクラス(たどる先)。
     *
     * @param  list<ReflectionParameter>  $parameters
     * @return list<string>
     */
    private function classesOf(array $parameters): array
    {
        $classes = [];

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && class_exists($type->getName()) && preg_match('/\A(App|Tests)\\\\/', $type->getName()) === 1) {
                $classes[] = $type->getName();
            }
        }

        return $classes;
    }
}
