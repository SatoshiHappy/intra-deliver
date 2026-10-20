<?php

namespace Tests\Feature\Ci;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RunsCiScripts;
use Tests\TestCase;

/**
 * scripts/ci/front --e2e の保護(設定のキャッシュがあるとき・接続先が接尾辞 _test で終わらないとき・決めたテスト DB の名前と違うとき・接尾辞が空のとき・
 * テスト DB の名前や E2E のポートを決められないときは、DB を初期化しない)と、決めた名前・ポートの渡し方を、偽の php・npm で確かめる。
 * 本物の DB は使わない。決め方そのものは {@see WorktreeTestEnvironmentTest}。道具は {@see RunsCiScripts}。
 */
final class FrontE2eGuardTest extends TestCase
{
    use RunsCiScripts;

    private const DEVELOPMENT_DATABASE = 'sample_app';

    private const TEST_DATABASE = 'sample_app_test';

    public function test_a_test_database_is_initialized_and_the_e2e_runs_on_the_decided_name_and_port(): void
    {
        $this->installFrontTools(self::DEVELOPMENT_DATABASE, '_test', self::TEST_DATABASE, '8190');

        $result = $this->runCi('front', ['--e2e']);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $calls = $this->calls();
        $this->assertCount(4, $calls);
        $migrate = 'artisan migrate:fresh --seed --force --no-interaction DB_DATABASE='.self::TEST_DATABASE;
        $this->assertSame($migrate, $calls[0]);
        $this->assertStringStartsWith('artisan db:seed --class=', $calls[1]);
        $this->assertStringStartsWith('artisan db:seed --class=', $calls[2]);
        $this->assertSame('npm run test:e2e E2E_PORT=8191', $calls[3]);
    }

    public function test_a_config_cache_stops_it_before_any_initialization(): void
    {
        $this->installFrontTools(self::DEVELOPMENT_DATABASE, '_test', self::TEST_DATABASE);
        mkdir($this->work.'/bootstrap/cache', 0777, true);
        file_put_contents($this->work.'/bootstrap/cache/config.php', "<?php\nreturn [];\n");

        $result = $this->runCi('front', ['--e2e']);

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertSame([], $this->calls());
        $this->assertStringContainsString('設定キャッシュ', $result->stderr);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function databasesNotSafeToInitialize(): array
    {
        return [
            '接続先が接尾辞で終わらない' => ['_test', self::DEVELOPMENT_DATABASE],
            '接続先が接尾辞で終わるが決めた名前と違う' => ['_test', 'other_test'],
            '接尾辞が空' => ['', self::TEST_DATABASE],
        ];
    }

    #[DataProvider('databasesNotSafeToInitialize')]
    public function test_a_database_that_is_not_the_test_database_is_not_initialized(
        string $suffix,
        string $connectedDatabase,
    ): void {
        $this->installFrontTools(self::DEVELOPMENT_DATABASE, $suffix, $connectedDatabase);

        $result = $this->runCi('front', ['--e2e']);

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertSame([], $this->calls());
        $this->assertStringContainsString('テスト用 DB でない', $result->stderr);
    }

    /**
     * @return array<string, array{string|null, string|null, string}>
     */
    public static function undecidableNamesOrPorts(): array
    {
        return [
            '開発用 DB の名前が無い' => [null, '8190', 'DB_DATABASE=<この作業木の開発用 DB の名前>'],
            '開発用 DB の名前が空' => ['', '8190', 'DB_DATABASE=<この作業木の開発用 DB の名前>'],
            '開発用 DB の名前が _test を含む' => ['sample_app_test', '8190', '_test を含まない名前にする'],
            'ポートが無い' => [self::DEVELOPMENT_DATABASE, null, 'SERVER_PORT=<この作業木の開発用サーバーのポート>'],
            'ポートが整数でない' => [self::DEVELOPMENT_DATABASE, 'abc', 'SERVER_PORT が 1〜65534 の整数でない'],
        ];
    }

    #[DataProvider('undecidableNamesOrPorts')]
    public function test_it_stops_with_the_reason_when_the_name_or_port_cannot_be_decided(
        ?string $developmentDatabase,
        ?string $serverPort,
        string $expectedInMessage,
    ): void {
        $this->installFrontTools($developmentDatabase, '_test', self::TEST_DATABASE, $serverPort);

        $result = $this->runCi('front', ['--e2e']);

        $this->assertNotSame(0, $result->exitCode, $result->describe());
        $this->assertSame([], $this->calls());
        $this->assertStringContainsString($expectedInMessage, $result->stderr);
    }
}
