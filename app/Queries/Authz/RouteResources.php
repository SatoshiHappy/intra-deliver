<?php

namespace App\Queries\Authz;

use App\Enums\Authz\ServiceAction;
use App\Support\RoutePlacement;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\RedirectController;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Routing\ViewController;
use Inertia\Controller as InertiaController;
use LogicException;
use ReflectionClass;
use ReflectionFunction;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

/**
 * アプリのルートと、ルートの画面・処理のリソース(docs の 設計/メニュー/認可との結び付け.md 2.3)。アプリのルートの定義はここだけに置き、
 * 付け忘れの検査・ルートのリソースを作る業務処理・入口・メニューの業務処理と表示判定が使う。規則は同じ節の「アプリのルート」
 * 「ルートの名前の規則」「置き場」「ルートのリソース」。名前と処理の規則のうち名前だけでは決まらないものは、付け忘れの検査
 * (tests/Feature/Routing/AppRoutesTest.php)が確かめる。
 */
class RouteResources
{
    /** ルートの名前の上限の文字数(長い方のセットの頭 http-services-unmanaged. を付けても、リソースグループ ID が 255 文字に収まる長さ) */
    public const MAX_NAME_LENGTH = 231;

    /** アプリがルートの書き方で使う、フレームワークの汎用の処理 */
    private const FRAMEWORK_CONTROLLERS = [InertiaController::class, RedirectController::class, ViewController::class];

    /** 名前の区切り(. で切った 1 つ)の形 */
    private const SEGMENT = '[a-z0-9_-]+';

    /** アプリの中のパスに使える文字だけでできている(RFC 3986 のパスの文字) */
    private const APP_PATH_CHARACTERS = '#\A[A-Za-z0-9\-._~!$&\'()*+,;=:@%/]*\z#';

    /** . か .. の区切り(%2e で書いた形を含む) */
    private const DOT_SEGMENT = '#/(?:\.|%2e){1,2}(?=/|\z)#i';

    public function __construct(private Router $router) {}

    /**
     * アプリのルートの一覧。
     *
     * @return list<Route>
     */
    public function appRoutes(): array
    {
        return array_values(array_filter($this->router->getRoutes()->getRoutes(), fn (Route $route) => $this->isAppRoute($route)));
    }

    public function isAppRoute(Route $route): bool
    {
        $controller = $route->getControllerClass();

        if ($controller !== null && in_array(ltrim($controller, '\\'), self::FRAMEWORK_CONTROLLERS, true)) {
            return true;
        }

        $uses = $route->getAction('uses');
        $file = match (true) {
            $uses instanceof Closure => (new ReflectionFunction($uses))->getFileName(),
            $controller !== null && class_exists($controller) => (new ReflectionClass($controller))->getFileName(),
            default => false,
        };

        return $file !== false && ! str_starts_with($file, base_path('vendor').DIRECTORY_SEPARATOR);
    }

    /**
     * その名前のアプリのルートがあるか。
     */
    public function hasAppRouteNamed(string $name): bool
    {
        return in_array($name, $this->appRouteNames(), true);
    }

    /**
     * 名前がアプリのルートの名前の規則(認可との結び付け.md 2.3 の「ルートの名前の規則」)のうち、名前だけで決まるものに合うか。
     * 一意かとモジュールの区切りは名前からは決まらないので、付け忘れの検査が確かめる。
     */
    public function followsNameRules(string $name): bool
    {
        return $this->hasNameForm($name)
            && ! in_array(true, array_map(fn (string $other) => str_starts_with($other, "{$name}."), $this->appRouteNames()), true);
    }

    /**
     * 置き場が http-services のルートの名前が、ルートとより長い名前の接頭辞が直下に混ざった接頭辞の下にあるか
     * (docs の 設計/メニュー/認可との結び付け.md 2.3 の「ルートの束と許可の置き場」)。付け忘れを止める検査が確かめ、業務処理は確かめない。
     */
    public function isUnderMixedPrefix(string $name): bool
    {
        if ($this->setOf($name) !== ServiceAction::SET_CODE) {
            return false;
        }

        $segments = explode('.', $name);
        $managedNames = $this->appRouteNamesIn(ServiceAction::SET_CODE);

        for ($depth = 1; $depth < count($segments); $depth++) {
            $prefix = implode('.', array_slice($segments, 0, $depth));
            $childDepths = array_map(
                fn (string $other) => substr_count($other, '.') + 1 - $depth,
                array_filter($managedNames, fn (string $other) => str_starts_with($other, "{$prefix}.")),
            );
            $hasRoutes = in_array(1, $childDepths, true);
            $hasLongerPrefixes = array_filter($childDepths, fn (int $childDepth) => $childDepth > 1) !== [];

            if ($hasRoutes && $hasLongerPrefixes) {
                return true;
            }
        }

        return false;
    }

    /**
     * ルートの置き場(リソースを置くセットの ID。規則 = 認可との結び付け.md 2.3 の「置き場」)。名前はルートを登録した後に付くので、
     * ルータの名前の索引(更新の時機がある)でなく、アプリのルートを今の名前でたどる。
     *
     * @throws LogicException 宣言の値が 2 つのセットのどちらでもないとき(ルートの定義の誤り)
     */
    public function setOf(string $routeName): string
    {
        foreach ($this->appRoutes() as $route) {
            if ($route->getName() === $routeName) {
                return $this->declaredSetOf($route);
            }
        }

        return ServiceAction::SET_CODE;
    }

    /**
     * 接頭辞の形(区切りを . でつないだ 1 つ以上)か。
     */
    public function isPrefixForm(string $prefix): bool
    {
        return preg_match('/\A'.self::SEGMENT.'(\.'.self::SEGMENT.')*\z/', $prefix) === 1;
    }

    /**
     * 置き場 $set に置くルートの名前(か接頭辞)のリソースグループ ID。
     */
    public function resourceGroupCodeOf(string $set, string $nameOrPrefix): string
    {
        return $set.'.'.$nameOrPrefix;
    }

    /**
     * 置き場 $set に置くルートの名前(か接頭辞)の親のリソースグループ ID(末端の区切りを除いた接頭辞のもの。区切りが 1 つならセット)。
     */
    public function parentResourceGroupCodeOf(string $set, string $nameOrPrefix): string
    {
        $lastDot = strrpos($nameOrPrefix, '.');

        return $lastDot === false ? $set : $this->resourceGroupCodeOf($set, substr($nameOrPrefix, 0, $lastDot));
    }

    /**
     * ルートの名前のリソース URI。
     */
    public function uriOf(string $name): string
    {
        return ServiceAction::RESOURCE_TYPE.'://'.str_replace('.', '/', $name);
    }

    /**
     * アプリの中のパスの形か(スキームとホストを持たないパス。docs の 設計/メニュー/表.md 1.7)。ブラウザは制御文字を取り除き
     * (/<タブ>/x は //x = 外部サイトになる)、. と .. の区切りを解くので、照合したルートと開くルートが食い違わない形に絞る。
     */
    public function isAppPath(string $path): bool
    {
        return str_starts_with($path, '/') && ! str_starts_with($path, '//')
            && preg_match(self::APP_PATH_CHARACTERS, $path) === 1
            && preg_match(self::DOT_SEGMENT, $path) === 0;
    }

    /**
     * HTTP メソッドとアプリの中のパスに合うアプリのルート(Laravel のルータと同じ順で照合。合うものが無ければ null)。
     *
     * ルータの match は共有されているルートのパラメータを書き換えるので、処理中の要求のルートを壊さないよう、束ねない
     * {@see Route::matches()} で照合する。アプリのルートはホストとスキームの条件を持たない(付け忘れの検査が止める)ので、
     * 照合用の要求がホストとスキームを持たなくても実際の要求と同じルートを選ぶ。
     */
    public function match(string $method, string $path): ?Route
    {
        return $this->matching($method, $path)[0] ?? null;
    }

    /**
     * {@see self::match()} で合ったルートの複製を照合用の要求に束ねたもの(名前と引数を読める)。複製なので共有のルートの引数は書き換わらない。
     */
    public function matchBound(string $method, string $path): ?Route
    {
        $matching = $this->matching($method, $path);

        return $matching === null ? null : (clone $matching[0])->bind($matching[1]);
    }

    /**
     * 照合で合ったアプリのルートと照合用の要求。
     *
     * @return array{Route, Request}|null
     */
    private function matching(string $method, string $path): ?array
    {
        if (! $this->isAppPath($path)) {
            return null;
        }

        try {
            $request = Request::create($path, strtoupper($method));
            $route = $this->firstRouteMatching($request);
        } catch (BadRequestException) {
            return null;
        }

        return $route !== null && $this->isAppRoute($route) ? [$route, $request] : null;
    }

    /**
     * 要求に合う最初のルート。フォールバックのルートは後に回す(ルータの照合と同じ順)。
     */
    private function firstRouteMatching(Request $request): ?Route
    {
        return collect($this->router->getRoutes()->get($request->getMethod()))
            ->sortBy(fn (Route $route) => (int) $route->isFallback)
            ->first(fn (Route $route) => $route->matches($request));
    }

    /**
     * @return list<string>
     */
    private function appRouteNames(): array
    {
        return array_values(array_filter(array_map(fn (Route $route) => $route->getName(), $this->appRoutes())));
    }

    /**
     * 置き場が $set のアプリのルートの名前。
     *
     * @return list<string>
     */
    private function appRouteNamesIn(string $set): array
    {
        return array_values(array_filter(array_map(
            fn (Route $route) => $this->declaredSetOf($route) === $set ? $route->getName() : null,
            $this->appRoutes(),
        )));
    }

    /**
     * ルートの定義の置き場の宣言(無ければ http-services)。
     *
     * @throws LogicException 宣言の値が 2 つのセットのどちらでもないとき
     */
    private function declaredSetOf(Route $route): string
    {
        $set = $route->getMetadata(RoutePlacement::METADATA_KEY, ServiceAction::SET_CODE);

        if (! is_string($set) || ! in_array($set, ServiceAction::SET_CODES, true)) {
            throw new LogicException("ルートの置き場の宣言が 2 つのセットのどちらでもありません: {$route->uri()}");
        }

        return $set;
    }

    private function hasNameForm(string $name): bool
    {
        return mb_strlen($name) <= self::MAX_NAME_LENGTH
            && preg_match('/\A'.self::SEGMENT.'(\.'.self::SEGMENT.')+\z/', $name) === 1;
    }
}
