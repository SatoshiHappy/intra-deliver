<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Uri;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel を起動するテストの基底クラス。DB を使うテストはこのクラスを直接継承せず、
 * DB の扱いを決めた基底クラス(Feature = {@see Support\TransactionalTestCase})を継承する。
 *
 * テストが開発用 DB を作り直さないための保護を 2 つ持つ(テスト DB・並列と専用 DB の名前 = docs の 設計/コード構成/検査/一括検査の段.md 6.5)。
 * - 設定キャッシュ(bootstrap/cache/config.php)があればアプリを作らない({@see self::createApplication()})。
 *   キャッシュがあると tests/bootstrap.php が置くテスト DB の指定が効かず、開発用 DB の設定のまま動くため。
 * - DB の初期化の前に、実際に接続した DB がテスト DB であることを確かめる({@see self::ensureTestDatabase()})。
 * 流用元 = chatpinet_2 の同名のクラス(既定テナントの文脈の開始を削り、設定キャッシュの保護を足した)。
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * アプリを作る。設定キャッシュのファイルがあれば、DB に触れる前に止める。
     *
     * @return Application
     *
     * @throws RuntimeException 設定キャッシュのファイルがあるとき
     */
    public function createApplication()
    {
        $app = parent::createApplication();
        $this->ensureConfigIsNotCached($app);

        return $app;
    }

    /**
     * 外部への HTTP の要求を、テストで偽装(Http::fake)したものだけにし、AI の接続先の鍵を設定から外す(.env の本物の鍵をテストの設定に載せない)。
     * 偽装の無い要求は例外になる(呼び手のモジュールのテストから AI のジョブが動いても本物の接続先に送らない = docs の 設計/AI/データと失敗と検査.md 8 節)。
     * 要求の本文を見るテストは、そのテストの中で偽の鍵を置く。
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        foreach (array_keys((array) config('ai.providers')) as $provider) {
            config(["ai.providers.{$provider}.key" => null]);
        }

        $this->resetRequestScope();
    }

    /**
     * テストが送る要求の前と後に、要求の寿命を区切る({@see self::resetRequestScope()})。
     *
     * @param  string  $method
     * @param  Uri|string  $uri
     * @param  array<array-key, mixed>  $parameters
     * @param  array<array-key, mixed>  $cookies
     * @param  array<array-key, mixed>  $files
     * @param  array<array-key, mixed>  $server
     * @param  string|null  $content
     * @return TestResponse<Response>
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->resetRequestScope();

        try {
            return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        } finally {
            $this->resetRequestScope();
        }
    }

    /**
     * 要求の寿命を区切る: scoped の実体(判定を求める者の事実など)と、ルートが覚えたコントローラ(注入した実体ごと)を捨てる。本番は要求ごとに
     * 新しい寿命になるが、1 つのテストの中の準備・要求・数える処理は同じアプリを使うため(docs の 設計/共通マスタ/ロールと認可/認可の確認/README.md 3.2)。
     */
    protected function resetRequestScope(): void
    {
        $this->app->forgetScopedInstances();

        foreach ($this->app->make(Router::class)->getRoutes()->getRoutes() as $route) {
            $route->flushController();
        }
    }

    /**
     * 設定キャッシュのファイルがあれば止める。
     *
     * @throws RuntimeException 設定キャッシュのファイルがあるとき
     */
    protected function ensureConfigIsNotCached(Application $app): void
    {
        $cachedConfig = $app->getCachedConfigPath();

        if (is_file($cachedConfig)) {
            throw new RuntimeException(
                "設定キャッシュ({$cachedConfig})があるので、テストを実行しない(tests/bootstrap.php が置くテスト DB の指定が効かず、開発用 DB を作り直すおそれがある)。"
                .'php artisan config:clear で消してから実行する',
            );
        }
    }

    /**
     * 既定の接続がテスト DB(Postgres で、実際に接続した DB の名前が guardrails.test_db_suffix で終わる)であることを確かめる。
     * migrate:fresh と表の全削除の前に呼び、テスト DB 以外を消す事故を止める。
     *
     * @throws RuntimeException テスト DB でないとき
     */
    protected function ensureTestDatabase(): void
    {
        $driver = $this->app['db']->connection()->getDriverName();

        if ($driver !== 'pgsql') {
            throw new RuntimeException("テスト DB ではないので初期化しない(ドライバ = {$driver}。Postgres だけを受ける)");
        }

        $suffix = (string) $this->app['config']->get('guardrails.test_db_suffix');
        $database = $this->connectedDatabaseName();

        if ($suffix === '' || preg_match($this->acceptedDatabaseNamePattern($suffix), $database) !== 1) {
            throw new RuntimeException("テスト DB ではないので初期化しない(DB = {$database}、要求する接尾辞 = {$suffix})");
        }
    }

    /**
     * テスト DB の名前の正規表現。接尾辞で終わる名前と、並列実行・専用 DB の名前(接尾辞 + `_test_<token>`。
     * Laravel の Illuminate\Testing\Concerns\TestDatabases が `{DB 名}_test_{token}` を作る)を受ける。
     */
    protected function acceptedDatabaseNamePattern(string $suffix): string
    {
        return '/'.preg_quote($suffix, '/').'(_test_[A-Za-z0-9]+)?$/D';
    }

    /**
     * 既定の接続が実際に接続している DB の名前(設定値でなくサーバーに問い合わせた値)。
     */
    protected function connectedDatabaseName(): string
    {
        return (string) $this->app['db']->connection()->selectOne('select current_database() as name')->name;
    }
}
