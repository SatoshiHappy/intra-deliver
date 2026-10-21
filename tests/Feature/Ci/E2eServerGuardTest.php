<?php

namespace Tests\Feature\Ci;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Architecture\Engine\Process;
use Tests\Architecture\Engine\ProcessResult;
use Tests\Support\RunsCiScripts;
use Tests\TestCase;

/**
 * scripts/ci/e2e-server の保護と呼び方(docs の 設計/コード構成/検査/一括検査の段.md 6.5 の並列(E))。作り直しの文の中の保護は本物の php で、
 * DB の宛先を届かない先にして走らせる(DB の文が流れれば接続の誤りになり、保護で止まったかを出た文で見分けられる)。
 */
final class E2eServerGuardTest extends TestCase
{
    use RunsCiScripts;

    private const TEMPLATE_DATABASE = 'sample_app_test';

    private const GROUP_DATABASE = 'sample_app_test_e2e_2';

    /** 本物の php で走らせるときの名前の頭(名前はこれか接尾辞から作る = 存在しない DB。届かない宛先とあわせて、本物の DB に触れない) */
    private const PROBE = 'intra_deliver_e2e_server_probe';

    /** 本物の php で走らせるときの DB の宛先(誰も聞いていないポート。DB_URL は host・port より優先されるので空にする) */
    private const UNREACHABLE_DATABASE = ['DB_HOST' => '127.0.0.1', 'DB_PORT' => '1', 'DB_URL' => ''];

    public function test_the_group_database_is_rebuilt_from_the_test_database_and_the_server_is_started(): void
    {
        $this->installE2eServerTools();

        $environment = $this->environment(self::TEMPLATE_DATABASE, self::GROUP_DATABASE);
        $result = $this->runCi('e2e-server', ['8192'], $environment);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $calls = $this->calls();
        $this->assertCount(2, $calls);
        $this->assertStringStartsWith('artisan tinker --execute ', $calls[0]);
        $this->assertStringContainsString('app()->configurationIsCached()', $calls[0]);
        // 接続先の確かめは drop より前
        $check = strpos($calls[0], 'select current_database()');
        $this->assertIsInt($check);
        $this->assertLessThan(strpos($calls[0], 'drop database if exists'), $check);
        $this->assertStringContainsString('guardrails.test_db_suffix', $calls[0]);
        $this->assertStringContainsString('drop database if exists', $calls[0]);
        $this->assertStringContainsString('with (force)', $calls[0]);
        $this->assertStringContainsString('create database', $calls[0]);
        $this->assertStringContainsString('template', $calls[0]);
        // 保守用の DB に接続し、名前は環境変数で渡す(写し元のテスト用 DB には接続しない)
        $this->assertStringEndsWith(
            ' DB_DATABASE=postgres E2E_GROUP_DATABASE='.self::GROUP_DATABASE
                .' E2E_TEMPLATE_DATABASE='.self::TEMPLATE_DATABASE,
            $calls[0],
        );
        $this->assertSame(
            'artisan serve --host=127.0.0.1 --port=8192 --no-reload DB_DATABASE='.self::GROUP_DATABASE,
            $calls[1],
        );
    }

    /**
     * @return array<string, array{string, string, list<string>, string}>
     */
    public static function refusedInputs(): array
    {
        return [
            'テスト DB が空' => ['', self::GROUP_DATABASE, ['8192'], 'E2E_TEMPLATE_DATABASE'],
            'テスト DB に使えない文字がある' => ['Sample-App_test', 'Sample-App_test_e2e_2', ['8192'], 'E2E_TEMPLATE_DATABASE'],
            '組の DB がテスト DB そのもの' => [self::TEMPLATE_DATABASE, self::TEMPLATE_DATABASE, ['8192'], 'DB_DATABASE'],
            '組の DB が別のテスト DB の組' => [self::TEMPLATE_DATABASE, 'other_test_e2e_1', ['8192'], 'DB_DATABASE'],
            '組の DB の番号が 0' => [self::TEMPLATE_DATABASE, self::TEMPLATE_DATABASE.'_e2e_0', ['8192'], 'DB_DATABASE'],
            '組の DB の番号が 10' => [self::TEMPLATE_DATABASE, self::TEMPLATE_DATABASE.'_e2e_10', ['8192'], 'DB_DATABASE'],
            '組の DB の番号が数字でない' => [self::TEMPLATE_DATABASE, self::TEMPLATE_DATABASE.'_e2e_x', ['8192'], 'DB_DATABASE'],
            '組の DB が空' => [self::TEMPLATE_DATABASE, '', ['8192'], 'DB_DATABASE'],
            'ポートが無い' => [self::TEMPLATE_DATABASE, self::GROUP_DATABASE, [], 'ポート'],
            'ポートが整数でない' => [self::TEMPLATE_DATABASE, self::GROUP_DATABASE, ['abc'], 'ポート'],
            'ポートが範囲外' => [self::TEMPLATE_DATABASE, self::GROUP_DATABASE, ['65536'], 'ポート'],
            // 54 + 5 + 6 = 65 文字(Postgres が切り詰める 63 を超える)
            '組の DB の名前が 63 文字を超える' => [
                str_repeat('a', 54).'_test',
                str_repeat('a', 54).'_test_e2e_2',
                ['8192'],
                '63 文字',
            ],
        ];
    }

    /**
     * @param  list<string>  $arguments
     */
    #[DataProvider('refusedInputs')]
    public function test_a_name_or_port_outside_the_rules_calls_nothing_and_stops_with_the_reason(
        string $templateDatabase,
        string $groupDatabase,
        array $arguments,
        string $expectedInMessage,
    ): void {
        $this->installE2eServerTools();

        $result = $this->runCi('e2e-server', $arguments, $this->environment($templateDatabase, $groupDatabase));

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertSame([], $this->calls());
        $this->assertStringContainsString($expectedInMessage, $result->stderr);
        $this->assertStringContainsString('scripts/ci/front から走らせる', $result->stderr);
    }

    public function test_a_failed_rebuild_stops_it_before_the_server_is_started_and_shows_the_reason(): void
    {
        $this->installE2eServerTools(tinkerExitCode: 1);

        $environment = $this->environment(self::TEMPLATE_DATABASE, self::GROUP_DATABASE);
        $result = $this->runCi('e2e-server', ['8192'], $environment);

        $this->assertNotSame(0, $result->exitCode, $result->describe());
        $calls = $this->calls();
        $this->assertCount(1, $calls);
        $this->assertStringStartsWith('artisan tinker --execute ', $calls[0]);
        // tinker の誤りは stdout に出るが、Playwright は webServer の stdout を捨てるので stderr に回っている
        $this->assertStringContainsString('source database is being accessed by other users', $result->stderr);
    }

    public function test_a_test_database_with_the_configured_suffix_passes_the_guards_and_reaches_the_database(): void
    {
        $template = self::PROBE.$this->suffix();

        $result = $this->runRealE2eServer($template, $template.'_e2e_1');

        // 保護を通り、接続先の確かめ(最初の DB の文)で、届かない宛先への接続の誤りになる
        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertStringContainsString('SQLSTATE[08006]', $result->stderr, $result->describe());
        // 最初の DB の文は接続先の確かめ(drop より前)
        $this->assertStringContainsString('select current_database()', $result->stderr, $result->describe());
    }

    public function test_a_test_database_without_the_configured_suffix_stops_before_any_database_statement(): void
    {
        $template = self::PROBE.'_check';

        $result = $this->runRealE2eServer($template, $template.'_e2e_1');

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertStringContainsString($this->suffix().' で終わらない', $result->stderr, $result->describe());
        $this->assertStringNotContainsString('SQLSTATE', $result->stderr, $result->describe());
    }

    public function test_a_test_database_of_only_the_suffix_stops_before_any_database_statement(): void
    {
        $result = $this->runRealE2eServer($this->suffix(), $this->suffix().'_e2e_1');

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertStringContainsString('前に名前が無い', $result->stderr, $result->describe());
        $this->assertStringNotContainsString('SQLSTATE', $result->stderr, $result->describe());
    }

    public function test_a_cached_configuration_stops_before_any_database_statement_and_names_the_cache(): void
    {
        // キャッシュの中の DB の宛先も届かない先にする(キャッシュがあると環境変数の宛先は効かない)
        $config = $this->app['config']->all();
        $config['database']['connections']['pgsql']['host'] = self::UNREACHABLE_DATABASE['DB_HOST'];
        $config['database']['connections']['pgsql']['port'] = self::UNREACHABLE_DATABASE['DB_PORT'];
        $config['database']['connections']['pgsql']['url'] = null;
        $cache = $this->root.'/config.php';
        file_put_contents($cache, '<?php return '.var_export($config, true).';'.PHP_EOL);
        $template = self::PROBE.$this->suffix();

        // 置き場は Laravel が APP_CONFIG_CACHE から決める(bootstrap/cache の外のキャッシュも止まる)
        $result = $this->runRealE2eServer($template, $template.'_e2e_1', ['APP_CONFIG_CACHE' => $cache]);

        $this->assertSame(1, $result->exitCode, $result->describe());
        // 出力ではキャッシュの場所がアプリのルートからの相対パスになるので、置き場のディレクトリとファイルの名前で照らす
        $this->assertStringContainsString(basename($this->root).'/config.php)', $result->stderr, $result->describe());
        $this->assertStringContainsString('php artisan config:clear', $result->stderr, $result->describe());
        $this->assertStringNotContainsString('SQLSTATE', $result->stderr, $result->describe());
    }

    private function suffix(): string
    {
        $suffix = config('guardrails.test_db_suffix');
        $this->assertIsString($suffix);
        $this->assertNotSame('', $suffix);

        return $suffix;
    }

    /**
     * 本物の scripts/ci/e2e-server を、本物の php でアプリのルートから、届かない DB の宛先で走らせる。
     *
     * @param  array<string, string>  $extra
     */
    private function runRealE2eServer(string $templateDatabase, string $groupDatabase, array $extra = []): ProcessResult
    {
        $names = $this->environment($templateDatabase, $groupDatabase);
        $environment = [...$names, ...self::UNREACHABLE_DATABASE, ...$extra];

        return Process::run(['scripts/ci/e2e-server', '8199'], base_path(), $environment, 120);
    }

    /**
     * 空文字は「無い」と同じ扱い(親の環境の値を引き継がないための上書き)。
     *
     * @return array<string, string>
     */
    private function environment(string $templateDatabase, string $groupDatabase): array
    {
        return ['E2E_TEMPLATE_DATABASE' => $templateDatabase, 'DB_DATABASE' => $groupDatabase];
    }
}
