<?php

namespace Tests\Feature\Deploy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\BootsWithEnvironmentOverrides;
use Tests\TestCase;

/**
 * 公開後の確認(docs の 設計/公開の配備/配備と戻し方.md 6.6 の表)のうち、アプリの起動の設定で決まる行を、公開用の app.env と同じ設定
 * (APP_ENV=production・APP_DEBUG=false・INERTIA_DEVTOOLS_ENABLED=false)で起動したアプリで確かめる。
 *
 * 本番の設定は、アプリを作る間だけ環境変数を置いて作り直すことで入れる(起動の後に設定を書き換えると、起動で 1 回だけ決まる
 * URL::forceHttps と開発用のパッケージのルートの登録を確かめられないため)。起動の間だけ置く(仕組みは BootsWithEnvironmentOverrides)。
 * 本番で起動すると破壊的なコマンド(migrate:fresh など)が禁止になるので、DB を使わない基底クラスにし(DB の初期化を流さない)、終わりに禁止を解く(コマンドのクラスの静的な状態で、同じプロセスのほかのテストに残るため)。
 * 開発用の支援のパッケージ(require-dev)は単体テストの実行中は自分を止めるが、本番の環境ではその判定が外れるので、入っていても
 * 本番では口が無いことをここで確かめる。入っていないこと(composer install --no-dev)はイメージで確かめる。
 * 応答のヘッダーの X-Powered-By(php.ini の expose_php)はウェブサーバーが付けるので、ここでは確かめない。
 */
final class PublishedResponsesTest extends TestCase
{
    use BootsWithEnvironmentOverrides;

    private const PUBLISHED_ENVIRONMENT = [
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'INERTIA_DEVTOOLS_ENABLED' => 'false',
    ];

    private const PUBLIC_HOST = 'intra-deliver.example.workers.dev';

    /**
     * @return array<string, string>
     */
    protected function environmentOverrides(): array
    {
        return self::PUBLISHED_ENVIRONMENT;
    }

    protected function tearDown(): void
    {
        DB::prohibitDestructiveCommands(false);

        parent::tearDown();
    }

    public function test_the_app_boots_with_the_published_settings(): void
    {
        $this->assertTrue($this->app->isProduction());
        $this->assertFalse($this->app->hasDebugModeEnabled());
    }

    public function test_the_health_route_responds_ok(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_the_browser_log_route_of_the_development_assistant_is_not_found(): void
    {
        $this->post('/_boost/browser-logs', ['logs' => []])->assertNotFound();
    }

    public function test_the_inertia_devtools_routes_are_not_found(): void
    {
        $this->get('/_inertia/devtools/entries')->assertNotFound();
    }

    public function test_a_get_to_a_post_only_route_is_refused_without_a_debug_page(): void
    {
        $response = $this->get('/account/logout');

        $response->assertStatus(405);
        $this->assertStringNotContainsString(base_path(), (string) $response->getContent());
        $this->assertStringNotContainsString('MethodNotAllowedHttpException', (string) $response->getContent());
    }

    public function test_redirects_and_asset_urls_point_to_https_on_the_forwarded_host_without_the_forwarded_port(): void
    {
        $this->registerProbeRoutes();
        $headers = [
            'X-Forwarded-For' => '203.0.113.10',
            'X-Forwarded-Host' => self::PUBLIC_HOST,
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Port' => '8080',
        ];

        $this->withHeaders($headers)->get('/_probe/redirect')->assertRedirect('https://'.self::PUBLIC_HOST.'/menu/sitemap');
        $this->withHeaders($headers)->get('/_probe/asset')->assertContent('https://'.self::PUBLIC_HOST.'/build/manifest.json');
    }

    public function test_urls_are_https_even_when_the_forwarded_proto_does_not_arrive(): void
    {
        $this->registerProbeRoutes();

        $this->withHeaders(['X-Forwarded-Host' => self::PUBLIC_HOST])->get('/_probe/redirect')
            ->assertRedirect('https://'.self::PUBLIC_HOST.'/menu/sitemap');
    }

    /**
     * 生成した URL を返すだけのルート(DB と web のグループを通さない)。アプリの 302 の行き先と資産の URL(Vite のタグ)は同じ URL の生成器で作られる。
     */
    private function registerProbeRoutes(): void
    {
        Route::get('/_probe/redirect', fn () => redirect('/menu/sitemap'));
        Route::get('/_probe/asset', fn () => asset('build/manifest.json'));
    }
}
