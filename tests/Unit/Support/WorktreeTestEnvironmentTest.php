<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Architecture\Engine\TempDirectory;
use Tests\Support\WorktreeTestEnvironment;
use UnexpectedValueException;

/**
 * テスト DB の名前と E2E のポートの決め方(Tests\Support\WorktreeTestEnvironment)。DB は使わない。
 * アプリのルートは一時のディレクトリに作り、.env と config/guardrails.php だけを置く。
 */
final class WorktreeTestEnvironmentTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $root = realpath(TempDirectory::create(dirname(__DIR__, 3).'/build/fixtures', 'worktree-env'));
        $this->assertNotFalse($root, '一時のディレクトリの実パスを決められない');
        $this->root = $root;
        mkdir($this->root.'/config');
        file_put_contents($this->root.'/config/guardrails.php', "<?php\n\nreturn ['test_db_suffix' => '_test'];\n");
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->root);

        parent::tearDown();
    }

    public function test_the_test_database_is_the_dotenv_name_plus_the_suffix(): void
    {
        $this->writeDotenv("DB_DATABASE=intra_app_3b1\n");

        $this->assertSame('intra_app_3b1_test', WorktreeTestEnvironment::testDatabaseName($this->root));
    }

    /**
     * bootstrap が置いた DB_DATABASE(テスト DB の名前)を引き継いだ子(paratest の worker)でも、接尾辞が重ならず同じ名前に決まる。
     */
    public function test_the_os_environment_variable_is_not_read(): void
    {
        $this->writeDotenv("DB_DATABASE=from_dotenv\n");
        $saved = getenv('DB_DATABASE');
        putenv('DB_DATABASE=from_dotenv_test');

        try {
            $this->assertSame('from_dotenv_test', WorktreeTestEnvironment::testDatabaseName($this->root));
        } finally {
            putenv($saved === false ? 'DB_DATABASE' : "DB_DATABASE={$saved}");
        }
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function missingDatabaseNames(): array
    {
        return [
            '.env に DB_DATABASE が無い' => ["APP_NAME=x\n"],
            '.env の DB_DATABASE が空' => ["DB_DATABASE=\n"],
            '.env のファイルが無い' => [null],
        ];
    }

    #[DataProvider('missingDatabaseNames')]
    public function test_it_stops_with_how_to_write_dotenv_when_the_name_is_missing(?string $dotenv): void
    {
        if ($dotenv !== null) {
            $this->writeDotenv($dotenv);
        }

        try {
            WorktreeTestEnvironment::testDatabaseName($this->root);
            $this->fail('例外にならなかった');
        } catch (UnexpectedValueException $e) {
            $this->assertStringContainsString(basename($this->root).'/.env', $e->getMessage());
            $this->assertStringContainsString('DB_DATABASE=<この作業木の開発用 DB の名前>', $e->getMessage());
            $this->assertStringNotContainsString($this->root.'/', $e->getMessage());
        }
    }

    public function test_the_result_ends_with_the_suffix_and_differs_from_the_development_name(): void
    {
        $this->writeDotenv("DB_DATABASE=intra_app\n");

        $name = WorktreeTestEnvironment::testDatabaseName($this->root);

        $this->assertStringEndsWith('_test', $name);
        $this->assertNotSame('intra_app', $name);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function developmentNamesContainingTheSuffix(): array
    {
        return [
            '接尾辞で終わる名前' => ['intra_app_test'],
            '接尾辞だけの名前' => ['_test'],
            '並列・専用 DB の形の名前' => ['x_test_test_1'],
        ];
    }

    #[DataProvider('developmentNamesContainingTheSuffix')]
    public function test_it_stops_when_the_development_name_contains_the_suffix(string $development): void
    {
        $this->writeDotenv("DB_DATABASE={$development}\n");

        try {
            WorktreeTestEnvironment::testDatabaseName($this->root);
            $this->fail('例外にならなかった');
        } catch (UnexpectedValueException $e) {
            $this->assertStringContainsString(basename($this->root).'/.env', $e->getMessage());
            $this->assertStringContainsString('_test を含まない名前にする', $e->getMessage());
        }
    }

    public function test_an_empty_suffix_stops_it(): void
    {
        $this->writeDotenv("DB_DATABASE=app\n");
        file_put_contents($this->root.'/config/guardrails.php', "<?php\n\nreturn ['test_db_suffix' => ''];\n");

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('test_db_suffix');

        WorktreeTestEnvironment::testDatabaseName($this->root);
    }

    public function test_the_e2e_port_is_the_server_port_plus_one(): void
    {
        $this->writeDotenv("SERVER_PORT=8190\n");

        $this->assertSame(8191, WorktreeTestEnvironment::e2ePort($this->root));
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function undecidablePorts(): array
    {
        return [
            '無い' => ["APP_NAME=x\n"],
            '空' => ["SERVER_PORT=\n"],
            '.env のファイルが無い' => [null],
            '整数でない' => ["SERVER_PORT=abc\n"],
            '小数' => ["SERVER_PORT=80.5\n"],
            'ゼロ' => ["SERVER_PORT=0\n"],
            '範囲を超える' => ["SERVER_PORT=65535\n"],
        ];
    }

    #[DataProvider('undecidablePorts')]
    public function test_it_stops_when_the_port_is_missing_or_not_an_integer(?string $dotenv): void
    {
        if ($dotenv !== null) {
            $this->writeDotenv($dotenv);
        }

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('SERVER_PORT');

        WorktreeTestEnvironment::e2ePort($this->root);
    }

    private function writeDotenv(string $contents): void
    {
        file_put_contents($this->root.'/.env', $contents);
    }
}
