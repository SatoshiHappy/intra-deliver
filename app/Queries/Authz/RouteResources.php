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
 * ルートのリソース(名前から 1 通りに決まる): リソースグループ ID = http-services. + 名前、URI = service:// + 名前の . を / に換えたもの。
 * 名前の接頭辞(. で切った先頭から末端の 1 つ手前まで)はすべてリソースグループ http-services. + 接頭辞 になり、最も短い接頭辞の親はセット http-services。
 */
class RouteResources
{
    /** ルートの名前の上限の文字数(リソースグループ ID が 255 文字に収まる長さ) */
    public const MAX_NAME_LENGTH = 241;

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
     * 名前がアプリのルートの名前の規則に合うか: <モジュール名の小文字>.<区切り>(.<区切り>…) の形(区切りは [a-z0-9_-]+)で 241 文字以内、
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
     * 接頭辞の形(区切りを . でつないだ 1 つ以上)か。
     */
    public function isPrefixForm(string $prefix): bool
    {
        return preg_match('/\A'.self::SEGMENT.'(\.'.self::SEGMENT.')*\z/', $prefix) === 1;
    }

    /**
     * ルートの名前(か接頭辞)のリソースグループ ID。
     */
    public function resourceGroupCodeOf(string $nameOrPrefix): string
    {
        return ServiceAction::SET_CODE.'.'.$nameOrPrefix;
    }

    /**
     * ルートの名前(か接頭辞)の親のリソースグループ ID(末端の区切りを除いた接頭辞のもの。区切りが 1 つならセット)。
     */
    public function parentResourceGroupCodeOf(string $nameOrPrefix): string
    {
        $lastDot = strrpos($nameOrPrefix, '.');

        return $lastDot === false ? ServiceAction::SET_CODE : $this->resourceGroupCodeOf(substr($nameOrPrefix, 0, $lastDot));
    }

    /**
     * ルートの名前のリソース URI。
     */
    public function uriOf(string $name): string
    {
        return ServiceAction::RESOURCE_TYPE.'://'.str_replace('.', '/', $name);
    }

    /**
     * アプリの中のパスの形か(docs の 設計/メニュー/表.md 1.7): / で始まり、2 文字目が / でなく、RFC 3986 のパスの文字
     * (英数字と -._~!$&'()*+,;=:@%/)だけでできていて、. と .. の区切り(%2e で書いた形を含む。大文字小文字を問わない)を含まない
     * (スキームとホストを持たないパス)。制御文字・空白・\・クエリ文字列(?)・フラグメント(#)・ASCII でない文字(パーセント符号化して持つ)は
     * 含まない。ブラウザは制御文字を取り除き(/<タブ>/x は //x = 外部サイトになる)、. と .. の区切りを解く(照合したルートと開くルートが食い違う)ため。
     */
    public function isAppPath(string $path): bool
    {
        return str_starts_with($path, '/') && ! str_starts_with($path, '//')
            && preg_match(self::APP_PATH_CHARACTERS, $path) === 1
            && preg_match(self::DOT_SEGMENT, $path) === 0;
    }

    /**
     * HTTP メソッドとアプリの中のパスに合うアプリのルート(Laravel のルータと同じ順で照合)。アプリの中のパスの形でないとき、合うルートが無いとき
     * (メソッドだけが違うときを含む)、不正な URI(BadRequest)のとき、合ったルートがアプリのルートでないときは null。
     * それ以外の例外はそのまま上げる。
     *
     * ルータの match は、合ったルートに照合用の要求を束ねて(共有されているルートのパラメータを書き換えて)返す。処理中の要求のルートを
     * 書き換えないように、束ねない {@see Route::matches()} で照合する。
     * 照合用の要求は実際の要求のホストとスキームを持たない(localhost の http)。アプリのルートはホストとスキームの条件を持たない前提で
     * (付け忘れの検査が止める)、照合はパスとメソッドだけで実際の要求と同じルートを選ぶ。
     */
    public function match(string $method, string $path): ?Route
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

        return $route !== null && $this->isAppRoute($route) ? $route : null;
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

    private function hasNameForm(string $name): bool
    {
        return mb_strlen($name) <= self::MAX_NAME_LENGTH
            && preg_match('/\A'.self::SEGMENT.'(\.'.self::SEGMENT.')+\z/', $name) === 1;
    }
}
