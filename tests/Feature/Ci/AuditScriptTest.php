<?php

namespace Tests\Feature\Ci;

use Tests\Architecture\Engine\Process;
use Tests\Architecture\Engine\ProcessResult;
use Tests\Support\RunsCiScripts;
use Tests\TestCase;

/**
 * 依存の監査 scripts/ci/audit.php が、見つかったものを門で止めずに出して記録し、監査が走らないときは止めること
 * (docs の 設計/コード構成/検査.md 6 節)。偽の composer・npm で走らせる。
 */
final class AuditScriptTest extends TestCase
{
    use RunsCiScripts;

    private const NO_COMPOSER_ADVISORY = '{"advisories":{},"abandoned":{}}';

    private const NO_NPM_ADVISORY = '{"auditReportVersion":2,"vulnerabilities":{},"metadata":{}}';

    private const WARNING = 'WARN  npm の脆弱性 | vite-plus | launch-editor: escape | high | vite-plus@1.3.0';

    /** 本物の composer が通信に失敗したときの標準エラーの形(原因は枠の中で、最後の行は使い方) */
    private const COMPOSER_NETWORK_FAILURE = "\nIn HttpDownloader.php line 329:\n\n"
        ."  Network disabled, request canceled: https://packagist.org/api/security-advisories/\n\n"
        ."audit [--no-dev] [-f|--format FORMAT] [--locked]\n";

    public function test_nothing_found_passes_with_an_ok_line_and_empties_the_record(): void
    {
        $this->installAuditTools(self::NO_COMPOSER_ADVISORY, self::NO_NPM_ADVISORY);
        $this->writeRecord("WARN  前の回\n");

        $result = $this->audit(githubActions: true);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertStringContainsString('OK  依存の監査', $result->stdout);
        $this->assertStringNotContainsString('WARN', $result->stdout);
        $this->assertStringNotContainsString('::warning', $result->stdout);
        $this->assertSame('', file_get_contents($this->recordPath()));
    }

    public function test_an_advisory_is_shown_and_recorded_as_a_warning_and_does_not_fail(): void
    {
        $this->installAuditTools(self::NO_COMPOSER_ADVISORY, $this->npmAdvisory(), npmExitCode: 1);

        $result = $this->audit();

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertStringContainsString(self::WARNING, $result->stdout);
        $info = 'INFO  詳しく(advisory の ID・URL)は composer audit --locked と npm audit で見る';
        $this->assertStringContainsString($info, $result->stdout);
        // 注意の表示は GitHub Actions の中だけ
        $this->assertStringNotContainsString('::warning', $result->stdout);
        $this->assertStringNotContainsString('OK', $result->stdout);
        $this->assertSame(self::WARNING."\n", file_get_contents($this->recordPath()));
    }

    public function test_an_advisory_inside_github_actions_also_raises_a_warning_annotation(): void
    {
        $this->installAuditTools(self::NO_COMPOSER_ADVISORY, $this->npmAdvisory(), npmExitCode: 1);

        $result = $this->audit(githubActions: true);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertStringContainsString("\n::warning title=依存の監査::WARN の行 1 件(門では止めない。", "\n".$result->stdout);
    }

    public function test_a_line_break_in_an_outside_value_does_not_start_a_new_line(): void
    {
        $this->installAuditTools(
            self::NO_COMPOSER_ADVISORY,
            $this->npmAdvisory("escape\n::error::fake"),
            npmExitCode: 1,
        );

        $result = $this->audit(githubActions: true);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertStringNotContainsString("\n::error", $result->stdout);
        $this->assertStringContainsString('| escape ::error::fake | high', $result->stdout);
    }

    public function test_an_audit_that_cannot_run_fails_with_the_cause_and_records_what_was_read(): void
    {
        $this->installAuditTools(
            '',
            $this->npmAdvisory(),
            composerExitCode: 255,
            npmExitCode: 1,
            composerStderr: self::COMPOSER_NETWORK_FAILURE,
        );
        $this->writeRecord("WARN  前の回\n");

        $result = $this->audit();

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertStringContainsString('NG  composer audit: composer audit の出力が JSON として読めない', $result->stdout);
        $this->assertStringContainsString('Network disabled, request canceled', $result->stdout);
        $this->assertStringContainsString(self::WARNING, $result->stdout);
        $this->assertSame(self::WARNING."\n", file_get_contents($this->recordPath()));
    }

    public function test_an_npm_error_fails_on_one_line_with_its_standard_error(): void
    {
        $error = ['code' => 'ENOTFOUND', 'summary' => "request failed\n::error::fake"];
        $this->installAuditTools(
            self::NO_COMPOSER_ADVISORY,
            (string) json_encode(['error' => $error]),
            npmExitCode: 1,
            npmStderr: "npm error network unreachable\n",
        );

        $result = $this->audit(githubActions: true);

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertStringNotContainsString("\n::error", "\n".$result->stdout);
        $line = 'NG  npm audit: npm audit がエラーを返した: ENOTFOUND request failed ::error::fake';
        $this->assertStringContainsString($line, $result->stdout);
        $this->assertStringContainsString('(標準エラー: npm error network unreachable)', $result->stdout);
    }

    public function test_a_legacy_command_marker_in_an_outside_value_is_broken_up(): void
    {
        $this->installAuditTools(
            self::NO_COMPOSER_ADVISORY,
            $this->npmAdvisory('escape ##[warning]fake'),
            npmExitCode: 1,
        );

        $result = $this->audit(githubActions: true);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertStringNotContainsString('##[', $result->stdout);
        $this->assertStringContainsString('escape ## [warning]fake', $result->stdout);
    }

    public function test_only_an_audit_that_cannot_run_fails_without_an_ok_line(): void
    {
        $this->installAuditTools(
            '',
            self::NO_NPM_ADVISORY,
            composerExitCode: 255,
            composerStderr: self::COMPOSER_NETWORK_FAILURE,
        );

        $result = $this->audit();

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertStringContainsString('NG  composer audit:', $result->stdout);
        $this->assertStringNotContainsString('OK', $result->stdout);
    }

    /**
     * npm audit --json の形(auditReportVersion 2)で、vite-plus に advisory が 1 件ある結果。
     */
    private function npmAdvisory(string $title = 'launch-editor: escape'): string
    {
        return (string) json_encode([
            'auditReportVersion' => 2,
            'vulnerabilities' => ['vite-plus' => [
                'name' => 'vite-plus',
                'severity' => 'high',
                'via' => [['title' => $title, 'url' => 'https://github.com/advisories/GHSA-698v-4r3x-3vcv']],
                'fixAvailable' => ['name' => 'vite-plus', 'version' => '1.3.0', 'isSemVerMajor' => true],
            ]],
            'metadata' => [],
        ]);
    }

    private function audit(bool $githubActions = false): ProcessResult
    {
        return Process::run(
            ['php', $this->work.'/scripts/ci/audit.php'],
            $this->work,
            ['PATH' => $this->tools.':'.getenv('PATH'), 'GITHUB_ACTIONS' => $githubActions ? 'true' : ''],
        );
    }

    private function recordPath(): string
    {
        return $this->work.'/build/dependency-audit.txt';
    }

    private function writeRecord(string $content): void
    {
        mkdir(dirname($this->recordPath()), 0777, true);
        file_put_contents($this->recordPath(), $content);
    }

    /**
     * 写した作業木に audit.php と更新チェックのクラスを置き、偽の composer・npm を置く。
     * 偽の composer・npm は、決めた出力を標準出力に、決めた文を標準エラーに書き、決めた終了コードで終わる。
     */
    private function installAuditTools(
        string $composerOutput,
        string $npmOutput,
        int $composerExitCode = 0,
        int $npmExitCode = 0,
        string $composerStderr = '',
        string $npmStderr = '',
    ): void {
        copy(base_path('scripts/ci/audit.php'), $this->work.'/scripts/ci/audit.php');
        copy(base_path('scripts/update-check/autoload.php'), $this->work.'/scripts/update-check/autoload.php');

        foreach (glob(base_path('scripts/update-check/src/*.php')) ?: [] as $source) {
            copy($source, $this->work.'/scripts/update-check/src/'.basename($source));
        }

        $tools = [
            'composer' => [$composerOutput, $composerStderr, $composerExitCode],
            'npm' => [$npmOutput, $npmStderr, $npmExitCode],
        ];

        foreach ($tools as $tool => [$out, $err, $exit]) {
            file_put_contents("{$this->tools}/{$tool}.out", $out);
            file_put_contents("{$this->tools}/{$tool}.err", $err);
            $script = "#!/bin/sh\ncat '{$this->tools}/{$tool}.out'\n"
                ."cat '{$this->tools}/{$tool}.err' >&2\nexit {$exit}\n";
            file_put_contents("{$this->tools}/{$tool}", $script);
            chmod("{$this->tools}/{$tool}", 0755);
        }
    }
}
