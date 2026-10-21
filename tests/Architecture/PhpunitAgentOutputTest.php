<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Architecture\Engine\Process;
use Tests\Architecture\Engine\TempDirectory;

/**
 * エージェントの中でも、phpunit の出力が警告の中身を残し、終了コードで失敗を返すこと(出力を要約に差し替える依存を入れない。
 * docs の 設計/コード構成/検査.md 6 節「結果の記録」)。見本は build/fixtures に一時生成する、PHPUnit の警告が出るテスト。
 */
final class PhpunitAgentOutputTest extends TestCase
{
    /** エージェントの中で動くことを示す環境変数(共通の AI_AGENT と、Claude Code・Codex が置くもの) */
    private const AGENT_ENVIRONMENT = ['AI_AGENT' => '1', 'CLAUDECODE' => '1', 'CODEX_SANDBOX' => 'seatbelt'];

    private string $workRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workRoot = TempDirectory::create(self::appRoot().'/build/fixtures', 'phpunit-agent-output');
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->workRoot);

        parent::tearDown();
    }

    public function test_a_phpunit_warning_is_shown_and_fails_the_run_inside_an_agent(): void
    {
        $autoload = self::appRoot().'/vendor/autoload.php';
        file_put_contents($this->workRoot.'/phpunit.xml', <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <phpunit bootstrap="{$autoload}" cacheResult="false" failOnAllIssues="true">
                <testsuites>
                    <testsuite name="Sample">
                        <file>WarningCase.php</file>
                    </testsuite>
                </testsuites>
            </phpunit>
            XML);
        file_put_contents($this->workRoot.'/WarningCase.php', <<<'PHP'
            <?php

            use PHPUnit\Framework\Attributes\DataProvider;
            use PHPUnit\Framework\TestCase;

            final class WarningCase extends TestCase
            {
                public static function rows(): array
                {
                    return ['a' => [1, 2]];
                }

                #[DataProvider('rows')]
                public function test_one_argument(int $a): void
                {
                    $this->assertSame(1, $a);
                }
            }
            PHP);

        $result = Process::run(
            [PHP_BINARY, 'vendor/bin/phpunit', '--configuration='.$this->workRoot.'/phpunit.xml'],
            self::appRoot(),
            self::AGENT_ENVIRONMENT,
        );

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertStringContainsString('WarningCase::rows', $result->stdout, $result->describe());
    }

    private static function appRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
