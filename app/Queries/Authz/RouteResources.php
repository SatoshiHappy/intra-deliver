<?php

namespace App\Queries\Authz;

use App\Enums\Authz\ServiceAction;
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
 * 付け忘れの検査・ルートのリソースを作る業務処理・入口・メニューの業務処理と表示判定が使う。
 *
 * アプリのルート = ルータに登録されたルートのうち、処理が vendor/ の外にあるもの(クロージャなら定義のあるファイル、コントローラならクラスのファイル)と、
 * アプリがルートの書き方で使うフレームワークの汎用の処理(Route::inertia・Route::redirect・Route::view)のもの。それ以外(パッケージのルート、
 * ヘルスチェック /up)はアプリのルートでなく、認可を通さない。
 * アプリのルートの処理にクロージャは使わない(ルートのキャッシュの後は直列化され、定義のファイルが分からないため。検査が止める)。
 * アプリのルートの名前は、アプリのルートの中で一意で、先頭の区切りはそのルートの処理が属するモジュール(コントローラは名前空間の <M>、
 * Route::inertia はページ名の <M>)。ルートの認可は名前だけで決まるので、同じ名前やほかのモジュールの名前が許可を共有しないように。
 * アプリのルートにホストの条件(Route::domain)とスキームの条件(http・https だけ)を付けない(照合はホストとスキームを見ないため)。
 * この 3 つは付け忘れの検査(tests/Feature/Routing/AppRoutesTest.php)が確かめる。
 *
 * 置き場(ルートのリソースを置くセット): ルートの定義(routes/)の宣言 {@see self::UNMANAGED} を付けたルートは設定不可のセット
 * http-services-unmanaged、宣言の無いルートは http-services。宣言を読むのは {@see self::setOf()} の 1 か所。
 *
 * ルートのリソース(置き場と名前から 1 通りに決まる): リソースグループ ID = <セット>. + 名前、URI = service:// + 名前の . を / に換えたもの
 * (置き場によらない)。名前の接頭辞(. で切った先頭から末端の 1 つ手前まで)はすべてリソースグループ <セット>. + 接頭辞 になり、
 * 最も短い接頭辞の親はセット。
 */
class RouteResources
{
    /** ルートの名前の上限の文字数(長い方のセットの頭 http-services-unmanaged. を付けても、リソースグループ ID が 255 文字に収まる長さ) */
    public const MAX_NAME_LENGTH = 231;

    /**
     * 設定不可のセットに置くルートに付ける置き場の宣言(ルートの metadata。routes/ で Route::metadata(RouteResources::UNMANAGED)->group(...))。
     * ルートのキャッシュの後も、ルートの action に入って残る。
     */
    public const UNMANAGED = ['authz' => ['set' => ServiceAction::UNMANAGED_SET_CODE]];

    /** 置き場の宣言を読む鍵(ルートの metadata の中の道) */
    private const SET_METADATA_KEY = 'authz.set';

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
     * 名前がアプリのルートの名前の規則に合うか: <モジュール名の小文字>.<区切り>(.<区切り>…) の形(区切りは [a-z0-9_-]+)で 231 文字以内、
     * かつ、ほかのアプリのルートの名前の接頭辞(. の区切りで切った先頭の部分)にならない。名前がアプリのルートの中で一意か(業務処理は登録済みの名前を
     * 受けるので、ここでは確かめない)と、先頭の区切りがそのルートの処理が属するモジュール(コントローラは名前空間の <M>、Route::inertia はページ名の
     * <M>。Route::redirect・Route::view は宣言したモジュールのどれか)かは、名前からは決まらず、付け忘れを止める検査(Feature テスト)が確かめる。
     */
    public function followsNameRules(string $name): bool
    {
        return $this->hasNameForm($name)
            && ! in_array(true, array_map(fn (string $other) => str_starts_with($other, "{$name}."), $this->appRouteNames()), true);
    }

    /**
     * 置き場が http-services のルートの名前が、ルートとより長い名前の接頭辞が直下に混ざった接頭辞の下にあるか(名前の規則の 1 つ。
     * フォルダの子はフォルダだけか、ルートだけにする = ルートの束)。混ざった接頭辞の下のルートは、直下のルートも、より長い接頭辞の下のルートも
     * 当たる。比べるのは置き場が http-services のアプリのルートどうしだけで、置き場が設定不可のセットのルートは当たらない(格子に出ないので
     * 束が要らない)。付け忘れを止める検査が確かめる(業務処理は確かめない = docs の 設計/メニュー/認可との結び付け.md 2.4)。
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
     * ルートの置き場(リソースを置くセットの ID)。その名前のアプリのルートの定義に宣言 {@see self::UNMANAGED} があれば設定不可のセット、
     * 宣言が無いか、その名前のアプリのルートが無ければ http-services(設定できる側。既定で禁止のまま)。名前はルートを登録した後に付くので、
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
        $set = $route->getMetadata(self::SET_METADATA_KEY, ServiceAction::SET_CODE);

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
