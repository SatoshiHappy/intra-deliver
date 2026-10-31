<?php

namespace Tests\Feature\Ci;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RunsCiScripts;
use Tests\TestCase;

/**
 * 段 scripts/ci/test が、phpunit(paratest)の後に時間の長いテストのクラスの一覧を junit で呼び、T が落ちても一覧を出して T の終了コードで終わり、
 * 一覧の失敗は WARN で T の結果を変えないことを、偽の record・phpunit・php で確かめる(docs の 設計/コード構成/検査/一括検査の段.md 6.5)。
 * 本物のテスト・DB は使わない。道具は {@see RunsCiScripts}。
 */
final class TestScriptTest extends TestCase
{
    use RunsCiScripts;

    private const LIST_CALL = 'php scripts/ci/slow-tests.php build/junit.xml';

    private const SLOW_WARNING = 'WARN  時間の長いテストのクラスを出せなかった(終了コード 3)';

    /**
     * @return array<string, array{list<string>, string, string}>
     */
    public static function modes(): array
    {
        return [
            '並列' => [[], 'php artisan test --without-tty --parallel --processes=3', '3 プロセス'],
            '直列' => [['--serial'], 'phpunit', '1 プロセス'],
        ];
    }

    /**
     * @param  list<string>  $arguments
     */
    #[DataProvider('modes')]
    public function test_the_list_follows_phpunit_with_the_junit_and_the_elapsed_line(
        array $arguments,
        string $phpunitCall,
        string $processes,
    ): void {
        $this->installTestTools();

        $result = $this->runCi('test', $arguments, $this->environment());

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertSame([$phpunitCall, self::LIST_CALL], $this->calls());
        $this->assertStringContainsString('FAKE-LIST', $result->stdout);
        $elapsed = '/^==> T の経過 [0-9]+ 秒・'.preg_quote($processes.'(終了コード 0)', '/').'$/m';
        $this->assertMatchesRegularExpression($elapsed, $result->stdout);
        $this->assertStringNotContainsString('WARN', $result->stdout);
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function modeCalls(): array
    {
        return array_map(fn (array $mode): array => [$mode[0], $mode[1]], self::modes());
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function modeArguments(): array
    {
        return array_map(fn (array $mode): array => [$mode[0]], self::modes());
    }

    /**
     * @param  list<string>  $arguments
     */
    #[DataProvider('modeCalls')]
    public function test_a_failing_phpunit_still_shows_the_list_and_keeps_its_code(
        array $arguments,
        string $phpunitCall,
    ): void {
        $this->installTestTools();

        $result = $this->runCi('test', $arguments, $this->environment(['FAKE_T_EXIT' => '7']));

        $this->assertSame(7, $result->exitCode, $result->describe());
        $this->assertSame([$phpunitCall, self::LIST_CALL], $this->calls());
        $this->assertStringContainsString('FAKE-LIST', $result->stdout);
        $this->assertStringContainsString('(終了コード 7)', $result->stdout);
        $this->assertStringNotContainsString('WARN', $result->stdout);
    }

    /**
     * @param  list<string>  $arguments
     */
    #[DataProvider('modeArguments')]
    public function test_a_failing_list_is_a_warning_and_does_not_change_the_result_of_phpunit(array $arguments): void
    {
        $this->installTestTools();

        $passed = $this->runCi('test', $arguments, $this->environment(['FAKE_SLOW_EXIT' => '3']));
        $this->resetCalls();
        $failed = $this->runCi('test', $arguments, $this->environment(['FAKE_SLOW_EXIT' => '3', 'FAKE_T_EXIT' => '7']));

        $this->assertSame(0, $passed->exitCode, $passed->describe());
        $this->assertStringContainsString(self::SLOW_WARNING, $passed->stdout);
        $this->assertStringContainsString('==> T の経過 ', $passed->stdout);
        $this->assertSame(7, $failed->exitCode, $failed->describe());
        $this->assertStringContainsString(self::SLOW_WARNING, $failed->stdout);
    }

    public function test_the_warning_is_also_an_actions_annotation_only_inside_github_actions(): void
    {
        $this->installTestTools();

        $outside = $this->runCi('test', [], $this->environment(['FAKE_SLOW_EXIT' => '3']));
        $inside = $this->runCi('test', [], $this->environment(['FAKE_SLOW_EXIT' => '3', 'GITHUB_ACTIONS' => 'true']));

        $this->assertStringNotContainsString('::warning', $outside->stdout);
        $this->assertStringContainsString("\n::warning title=時間の長いテストのクラス::", "\n".$inside->stdout);
        $this->assertSame(0, $inside->exitCode, $inside->describe());
    }

    public function test_an_invalid_option_or_process_count_is_a_usage_error(): void
    {
        $this->installTestTools();

        $option = $this->runCi('test', ['--parallel'], $this->environment());
        $processes = $this->runCi('test', [], $this->environment(['CI_TEST_PROCESSES' => '0']));

        $this->assertSame(2, $option->exitCode, $option->describe());
        $this->assertSame(2, $processes->exitCode, $processes->describe());
        $this->assertSame([], $this->calls());
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function environment(array $extra = []): array
    {
        // 親の環境の値を引き継がない(既定は 0・一覧は成功・Actions の外)
        return [
            'CI_TEST_PROCESSES' => '3',
            'FAKE_T_EXIT' => '0',
            'FAKE_SLOW_EXIT' => '0',
            'GITHUB_ACTIONS' => '',
            ...$extra,
        ];
    }

    /**
     * 偽の record は記録せずに道具を走らせる(work)。偽の phpunit(work の vendor/bin)・偽の php(tools)は「道具の名前と引数」を
     * calls.log に 1 行書く。phpunit・artisan は FAKE_T_EXIT、一覧の道具(scripts/ci/slow-tests.php)は FAKE_SLOW_EXIT で終わる。
     */
    private function installTestTools(): void
    {
        $calls = escapeshellarg($this->tools.'/calls.log');

        $this->writeWorkFile('scripts/ci/record', "#!/bin/sh\nshift 2\nexec \"\$@\"\n");
        $this->writeWorkFile('vendor/bin/phpunit', implode("\n", [
            '#!/bin/sh',
            "printf '%s\\n' \"phpunit\${1:+ \$*}\" >> {$calls}",
            'exit "$FAKE_T_EXIT"',
            '',
        ]));
        $this->writeTool('php', implode("\n", [
            '#!/bin/sh',
            "printf '%s\\n' \"php \$*\" >> {$calls}",
            'case "$1" in',
            '  artisan) exit "$FAKE_T_EXIT" ;;',
            '  scripts/ci/slow-tests.php)',
            '    if [ "$FAKE_SLOW_EXIT" -ne 0 ]; then echo "一覧を出せない" >&2; exit "$FAKE_SLOW_EXIT"; fi',
            '    echo FAKE-LIST; exit 0 ;;',
            'esac',
            'exit 99',
            '',
        ]));
    }

    private function writeWorkFile(string $path, string $script): void
    {
        $full = $this->work.'/'.$path;

        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0777, true);
        }

        file_put_contents($full, $script);
        chmod($full, 0755);
    }
}
