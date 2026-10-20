<?php

namespace Tests\Feature;

use Tests\Architecture\Engine\Process;
use Tests\Architecture\Engine\TempDirectory;
use Tests\TestCase;

/**
 * 設定キャッシュがあるとき、テストがアプリを作らずに止まること({@see TestCase::createApplication()})を確かめる。
 *
 * 設定キャッシュの置き場を環境変数 APP_CONFIG_CACHE で一時ファイルに差し替えた子プロセスで、DB を使うテストを走らせる。
 * キャッシュの中身は今の設定で、DB の名前だけを存在しない名前にする(保護が働かなくても、どの DB も作り直されない)。
 */
final class ConfigCacheGuardTest extends TestCase
{
    private const PROBE_DATABASE = 'intra_deliver_config_cache_probe';

    private string $workRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workRoot = TempDirectory::create(base_path('build/fixtures'), 'config-cache');
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->workRoot);

        parent::tearDown();
    }

    public function test_tests_stop_before_touching_the_database_when_the_configuration_is_cached(): void
    {
        $result = Process::run(
            [PHP_BINARY, 'vendor/bin/phpunit', '--no-logging', '--do-not-cache-result', 'tests/Feature/SmokeTest.php'],
            base_path(),
            ['APP_CONFIG_CACHE' => $this->writeCachedConfig()],
        );

        $this->assertNotSame(0, $result->exitCode, $result->describe());
        // 出力の形式(AI エージェントの下では JSON になり、日本語がエスケープされる)に依らないよう、メッセージの ASCII の部分で確かめる
        $this->assertStringContainsString('php artisan config:clear', $result->stdout, $result->describe());
        $this->assertStringNotContainsString(self::PROBE_DATABASE, $result->stdout, $result->describe());
    }

    /**
     * 今の設定を、DB の名前だけ差し替えて設定キャッシュの形で書き、パスを返す。
     */
    private function writeCachedConfig(): string
    {
        $config = $this->app['config']->all();
        $config['database']['connections']['pgsql']['database'] = self::PROBE_DATABASE;
        $path = $this->workRoot.'/config.php';

        file_put_contents($path, '<?php return '.var_export($config, true).';'.PHP_EOL);

        return $path;
    }
}
