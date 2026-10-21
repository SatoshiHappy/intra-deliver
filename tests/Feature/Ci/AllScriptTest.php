<?php

namespace Tests\Feature\Ci;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Architecture\Engine\TempDirectory;
use Tests\Support\RunsCiScripts;
use Tests\TestCase;

/**
 * 一括検査 scripts/ci/all の規則(失敗の集約・無い段・E の飛ばし・D の飛ばし・依存の監査の注意の件数。docs の 設計/コード構成/検査.md 6 節)を、
 * 偽の段で確かめる。
 * 本物の段・DB・記録は使わない。道具は {@see RunsCiScripts}。
 */
final class AllScriptTest extends TestCase
{
    use RunsCiScripts;

    private const ORDER = ['format', 'static', 'psr4', 'secrets', 'deps', 'front', 'test', 'verify', 'front --e2e'];

    public function test_all_stages_succeed_in_order_and_end_with_zero(): void
    {
        $this->installStages();

        $result = $this->runCi('all');

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertSame(self::ORDER, $this->calls());
        $this->assertStringContainsString('S static: 成功', $result->stdout);
        $this->assertStringContainsString('E front --e2e: 成功', $result->stdout);
    }

    public function test_a_failed_stage_does_not_stop_the_rest_and_ends_with_one(): void
    {
        $this->installStages(['static' => 3]);

        $result = $this->runCi('all');

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertSame(self::ORDER, $this->calls());
        $this->assertStringContainsString('S static: 失敗(終了コード 3)', $result->stdout);
    }

    public function test_a_missing_stage_script_is_a_failure_and_the_rest_continue(): void
    {
        $this->installStages();
        unlink($this->work.'/scripts/ci/psr4');

        $result = $this->runCi('all');

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertSame(array_values(array_diff(self::ORDER, ['psr4'])), $this->calls());
        $this->assertStringContainsString('P psr4: 無い', $result->stdout);
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function withAndWithoutFull(): array
    {
        return ['既定' => [[]], '--full' => [['--full']]];
    }

    /**
     * @param  list<string>  $arguments
     */
    #[DataProvider('withAndWithoutFull')]
    public function test_e2e_is_skipped_when_the_front_stage_did_not_succeed(array $arguments): void
    {
        $this->installStages(['front' => 1]);

        $result = $this->runCi('all', $arguments);

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertNotContains('front --e2e', $this->calls());
        $this->assertStringContainsString('E front --e2e: 飛ばした(N が成功していない)', $result->stdout);
    }

    public function test_deps_is_skipped_on_the_same_day_when_nothing_it_reads_changed(): void
    {
        $this->installStages();
        file_put_contents($this->work.'/composer.json', '{}');

        $this->assertSame(0, $this->runCi('all')->exitCode);
        $this->assertFileExists($this->work.'/build/deps.verified');
        $this->resetCalls();

        $result = $this->runCi('all');

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertNotContains('deps', $this->calls());
        $this->assertStringContainsString('D deps: 飛ばした(最後に全成功し依存の監査の注意が 0 件だった検査から', $result->stdout);
    }

    public function test_deps_runs_with_full_or_when_its_input_or_the_day_changed(): void
    {
        $this->installStages();
        file_put_contents($this->work.'/composer.json', '{}');
        $this->assertSame(0, $this->runCi('all')->exitCode);

        $this->resetCalls();
        $this->assertSame(0, $this->runCi('all', ['--full'])->exitCode);
        $this->assertContains('deps', $this->calls());

        $this->resetCalls();
        file_put_contents($this->work.'/composer.json', '{"changed":true}');
        $this->assertSame(0, $this->runCi('all')->exitCode);
        $this->assertContains('deps', $this->calls());

        $this->resetCalls();
        $this->assertSame(0, $this->runCi('all')->exitCode);
        $this->assertNotContains('deps', $this->calls());

        $this->resetCalls();
        $this->installDate('2026-10-20');
        $this->assertSame(0, $this->runCi('all')->exitCode);
        $this->assertContains('deps', $this->calls());
    }

    /**
     * D の鍵の入力(scripts/ci/deps は偽の段そのものなので除く)。
     *
     * @return array<string, array{string}>
     */
    public static function depsInputs(): array
    {
        $files = [
            'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'config/guardrails_allow.php',
            'scripts/ci/licenses.php', 'scripts/ci/audit.php', 'scripts/update-check/autoload.php',
            'vendor/composer/installed.json',
            'node_modules/.package-lock.json', 'tests/Architecture/Engine/Sample.php',
            'tests/Architecture/Policy/Sample.php', 'scripts/update-check/src/Sample.php',
            'scripts/update-check/src/Added.php',
        ];

        return array_combine($files, array_map(fn (string $file): array => [$file], $files));
    }

    #[DataProvider('depsInputs')]
    public function test_deps_runs_again_when_a_file_it_reads_is_added_or_changed(string $file): void
    {
        $this->installStages();
        $path = $this->work.'/'.$file;

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        $this->assertSame(0, $this->runCi('all')->exitCode);
        $this->resetCalls();
        file_put_contents($path, "<?php\n// changed\n");

        $result = $this->runCi('all');

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertContains('deps', $this->calls());
    }

    public function test_while_audit_warnings_remain_deps_runs_every_time_and_counts_them(): void
    {
        $this->installStages();
        $this->installDepsWritingAuditWarnings(2);
        $line = 'D 依存の監査の注意: WARN の行 2 件(build/dependency-audit.txt。門では止めない)';

        $result = $this->runCi('all');

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertStringContainsString($line, $result->stdout);

        $this->resetCalls();
        $result = $this->runCi('all');

        $this->assertContains('deps', $this->calls());
        $this->assertStringContainsString($line, $result->stdout);
    }

    public function test_after_no_audit_warning_deps_is_skipped_and_an_old_record_is_not_counted(): void
    {
        $this->installStages();
        $this->installDepsWritingAuditWarnings(0);
        $this->assertSame(0, $this->runCi('all')->exitCode);

        // 単体の deps などが残した記録
        file_put_contents($this->work.'/build/dependency-audit.txt', "WARN  前の回\n");
        $this->resetCalls();
        $result = $this->runCi('all');

        $this->assertNotContains('deps', $this->calls());
        $this->assertStringNotContainsString('D 依存の監査の注意:', $result->stdout);
    }

    public function test_a_failed_deps_key_shows_no_count_and_the_next_run_follows_the_last_audit(): void
    {
        // 前の D が注意ありで終わった: 鍵の失敗の回は件数の行なし、戻した次の回は D が走り件数の行が出る
        $this->installStages();
        $this->installDepsWritingAuditWarnings(2);
        $this->assertSame(0, $this->runCi('all')->exitCode);
        $engine = $this->work.'/tests/Architecture/Engine';
        rename($engine, $engine.'.away');

        $result = $this->runCi('all');

        $this->assertStringContainsString('D deps: 失敗(D の鍵を計算できない', $result->stdout);
        $this->assertStringNotContainsString('D 依存の監査の注意:', $result->stdout);

        rename($engine.'.away', $engine);
        $this->resetCalls();
        $result = $this->runCi('all');

        $this->assertContains('deps', $this->calls());
        $this->assertStringContainsString('D 依存の監査の注意: WARN の行 2 件', $result->stdout);
    }

    public function test_a_failed_deps_key_after_no_audit_warning_shows_no_count_and_the_next_run_skips_deps(): void
    {
        // 前の D が 0 件だった: 鍵の失敗の回も、戻した次の回も件数の行なし(次の回は D を飛ばす)
        $this->installStages();
        $this->installDepsWritingAuditWarnings(0);
        $this->assertSame(0, $this->runCi('all')->exitCode);
        $engine = $this->work.'/tests/Architecture/Engine';
        rename($engine, $engine.'.away');

        $this->assertStringNotContainsString('D 依存の監査の注意:', $this->runCi('all')->stdout);

        rename($engine.'.away', $engine);
        $this->resetCalls();
        $result = $this->runCi('all');

        $this->assertNotContains('deps', $this->calls());
        $this->assertStringNotContainsString('D 依存の監査の注意:', $result->stdout);
    }

    public function test_a_failed_deps_leaves_no_record_so_the_next_run_runs_it_again(): void
    {
        $this->installStages();
        $this->assertSame(0, $this->runCi('all')->exitCode);
        $this->assertFileExists($this->work.'/build/deps.verified');

        $this->installStages(['deps' => 1]);
        $this->assertSame(1, $this->runCi('all', ['--full'])->exitCode);
        $this->assertFileDoesNotExist($this->work.'/build/deps.verified');

        $this->installStages();
        $this->resetCalls();
        $result = $this->runCi('all');

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertContains('deps', $this->calls());
    }

    public function test_deps_fails_when_the_key_cannot_be_computed(): void
    {
        $this->installStages();
        TempDirectory::remove($this->work.'/tests/Architecture/Engine');

        $result = $this->runCi('all');

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertNotContains('deps', $this->calls());
        $this->assertStringContainsString('D deps: 失敗(D の鍵を計算できない', $result->stdout);
        $this->assertContains('verify', $this->calls());
    }

    /**
     * 偽の deps を、依存の監査の記録(scripts/ci/audit.php が書くもの)に WARN の行を $count 行書いてから成功するものに置き換える。
     */
    private function installDepsWritingAuditWarnings(int $count): void
    {
        $path = $this->work.'/scripts/ci/deps';
        $indexes = $count > 0 ? range(1, $count) : [];
        $lines = implode('', array_map(fn (int $index): string => "WARN  行 {$index}\\n", $indexes));
        $script = file_get_contents($path);
        $this->assertIsString($script);
        $record = "mkdir -p build\nprintf '{$lines}' > build/dependency-audit.txt\n";
        file_put_contents($path, str_replace("exit 0\n", $record."exit 0\n", $script));
    }
}
