<?php

namespace Tests\Unit\UpdateCheck;

use Tests\Architecture\Engine\TempDirectory;
use UpdateCheck\AuditCheck;
use UpdateCheck\CommandResult;
use UpdateCheck\ComposerOutdatedCheck;
use UpdateCheck\NpmOutdatedCheck;
use UpdateCheck\Section;
use UpdateCheck\Status;

/**
 * #1・#2・#3: コマンドの出力(JSON)の形で判定する。読めない・期待した鍵が無いときは確認できなかった(終了コードは使わない)。
 */
final class CommandChecksTest extends UpdateCheckTestCase
{
    private const COMPOSER_OUTDATED = 'composer outdated --locked --direct --format=json --ignore-platform-reqs --no-interaction';

    private const COMPOSER_AUDIT = 'composer audit --locked --format=json --no-interaction';

    private string $temp;

    protected function setUp(): void
    {
        parent::setUp();
        // npm ci の一時のディレクトリの置き場をテストごとに分ける(共有の置き場は、並列の走行でほかのテストのプロセスも作って消す)
        $this->temp = TempDirectory::create(sys_get_temp_dir(), 'updcheck-commands');
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->temp);

        parent::tearDown();
    }

    private function npmCheck(FakeCommandRunner $runner, string $root = '/x'): NpmOutdatedCheck
    {
        return new NpmOutdatedCheck($runner, $root, $this->temp);
    }

    private function runner(string $command, string $stdout, int $exitCode = 0): FakeCommandRunner
    {
        return new FakeCommandRunner([$command => new CommandResult($exitCode, $stdout, '')]);
    }

    private function npmRunner(string $outdated, int $installExit = 0): FakeCommandRunner
    {
        return new FakeCommandRunner([
            'npm ci --ignore-scripts' => new CommandResult($installExit, '', $installExit === 0 ? '' : 'npm error network'),
            'npm outdated --json' => new CommandResult(1, $outdated, ''),
        ]);
    }

    /**
     * @return list<string>
     */
    private function cells(Section $section, int $index = 0): array
    {
        return $section->rows[$index]->cells;
    }

    public function test_composer_outdated_は_locked_の一覧を更新ありにする(): void
    {
        $json = (string) json_encode(['locked' => [['name' => 'a/b', 'version' => '1.0.0', 'latest' => '1.1.0', 'latest-status' => 'semver-safe-update']]]);

        $section = (new ComposerOutdatedCheck($this->runner(self::COMPOSER_OUTDATED, $json, 0), '/x'))->run();

        $this->assertSame(Status::Update, $section->rows[0]->status);
        $this->assertSame(['a/b', '1.0.0', '1.1.0', 'semver-safe-update'], $this->cells($section));
        $this->assertSame([], $section->unconfirmed);
    }

    public function test_composer_outdated_は終了コードが_0_以外でも出力が読めれば判定する(): void
    {
        $section = (new ComposerOutdatedCheck($this->runner(self::COMPOSER_OUTDATED, '{"locked":[]}', 1), '/x'))->run();

        $this->assertSame([], $section->rows);
        $this->assertSame([], $section->unconfirmed);
    }

    public function test_composer_outdated_は読めない出力と鍵の無い出力を確認できなかったにする(): void
    {
        foreach (['not json', '{"installed":[]}', ''] as $stdout) {
            $section = (new ComposerOutdatedCheck($this->runner(self::COMPOSER_OUTDATED, $stdout), '/x'))->run();

            $this->assertSame([], $section->rows);
            $this->assertCount(1, $section->unconfirmed);
        }
    }

    public function test_npm_outdated_は入っていない同じ版の任意の依存を数えない(): void
    {
        $json = (string) json_encode([
            'a' => ['current' => '1.0.0', 'wanted' => '1.0.1', 'latest' => '2.0.0'],
            'win32-only' => ['wanted' => '4.3.3', 'latest' => '4.3.3'],
            'win32-old' => ['wanted' => '4.3.3', 'latest' => '4.4.0'],
            'dup' => [['current' => '1.0.0', 'wanted' => '1.0.0', 'latest' => '1.0.1'], ['current' => '0.9.0', 'wanted' => '0.9.0', 'latest' => '1.0.1']],
        ]);

        $section = $this->npmCheck($this->npmRunner($json))->run();

        $this->assertSame(['a', 'win32-old', 'dup', 'dup'], array_map(static fn ($row): string => $row->cells[0], $section->rows));
        $this->assertSame(['a', '1.0.0', '1.0.1', '2.0.0'], $this->cells($section));
    }

    public function test_npm_outdated_は何も古くない時の空の表を問題なしにする(): void
    {
        $section = $this->npmCheck($this->npmRunner('{}'))->run();

        $this->assertSame([], $section->rows);
        $this->assertSame([], $section->unconfirmed);
    }

    public function test_npm_outdated_は空の出力とエラーの_json_を確認できなかったにする(): void
    {
        foreach (['', '{"error":{"code":"E429","summary":"too many requests"}}'] as $stdout) {
            $section = $this->npmCheck($this->npmRunner($stdout))->run();

            $this->assertCount(1, $section->unconfirmed);
        }
    }

    public function test_npm_のエラーが_429_なら理由を上限にする(): void
    {
        $stdout = '{"error":{"code":"E429","summary":"429 Too Many Requests"}}';

        $section = $this->npmCheck($this->npmRunner($stdout))->run();

        $this->assertStringStartsWith('上限', $section->unconfirmed[0]->reason);
    }

    public function test_audit_は脆弱性と放棄されたパッケージを要対応にする(): void
    {
        $composer = (string) json_encode([
            'advisories' => ['a/b' => [['title' => 'XSS', 'severity' => 'high', 'affectedVersions' => '<1.2']]],
            'abandoned' => ['c/d' => 'e/f', 'g/h' => null],
        ]);
        $npm = (string) json_encode(['vulnerabilities' => ['lodash' => ['name' => 'lodash', 'severity' => 'high', 'via' => [['title' => 'Prototype pollution'], 'other'], 'fixAvailable' => ['name' => 'lodash', 'version' => '4.17.21']]]]);
        $runner = new FakeCommandRunner([
            self::COMPOSER_AUDIT => new CommandResult(1, $composer, ''),
            'npm audit --json' => new CommandResult(1, $npm, ''),
        ]);

        $section = (new AuditCheck($runner, '/x'))->run();

        $this->assertSame([], $section->unconfirmed);
        $this->assertSame([Status::Action, Status::Action, Status::Action, Status::Action], array_map(static fn ($row): Status => $row->status, $section->rows));
        $this->assertSame(['composer の脆弱性', 'a/b', 'XSS', 'high', '<1.2'], $section->rows[0]->cells);
        $this->assertSame(['composer の放棄', 'c/d', '放棄されたパッケージ', '', '代わり: e/f'], $section->rows[1]->cells);
        $this->assertSame(['npm の脆弱性', 'lodash', 'Prototype pollution / via other', 'high', 'lodash@4.17.21'], $section->rows[3]->cells);
    }

    public function test_audit_は片方が確認できなくても残りを続ける(): void
    {
        $runner = new FakeCommandRunner([
            self::COMPOSER_AUDIT => new CommandResult(0, '{"advisories":[],"abandoned":[]}', ''),
            'npm audit --json' => new CommandResult(1, '{"message":"request failed","error":{"summary":"","detail":""}}', ''),
        ]);

        $section = (new AuditCheck($runner, '/x'))->run();

        $this->assertSame([], $section->rows);
        $this->assertCount(1, $section->unconfirmed);
        $this->assertSame('npm audit', $section->unconfirmed[0]->subject);
    }

    public function test_audit_が確認できなかった理由に標準エラーの行を全部添える(): void
    {
        // composer は原因の枠の後に使い方の行を出す(最後の行だけでは原因が出ない)
        $stderr = "\nIn HttpDownloader.php line 329:\n\n  Network disabled, request canceled\n\naudit [--locked]\n";
        $runner = new FakeCommandRunner([
            self::COMPOSER_AUDIT => new CommandResult(255, '', $stderr),
            'npm audit --json' => new CommandResult(0, '{"vulnerabilities":{}}', ''),
        ]);

        $section = (new AuditCheck($runner, '/x'))->run();

        $this->assertCount(1, $section->unconfirmed);
        $this->assertSame(
            'composer audit の出力が JSON として読めない(標準エラー: In HttpDownloader.php line 329: / '
                .'Network disabled, request canceled / audit [--locked])',
            $section->unconfirmed[0]->reason,
        );
    }

    public function test_audit_の標準エラーが長いときは後ろを残し_空ならそう書く(): void
    {
        // 前置きの通知は先頭に、原因の枠と使い方の行は最後に出る
        $notice = 'Deprecated: a plugin uses an old interface and will stop working soon';
        $cause = "\n  Network disabled, request canceled\naudit [--locked]\n";
        $stderr = implode("\n", array_fill(0, 10, $notice)).$cause;
        $runner = new FakeCommandRunner([
            self::COMPOSER_AUDIT => new CommandResult(255, '', $stderr),
            'npm audit --json' => new CommandResult(1, 'not json', ''),
        ]);

        $section = (new AuditCheck($runner, '/x'))->run();

        $this->assertCount(2, $section->unconfirmed);
        $this->assertStringContainsString('(標準エラー: …', $section->unconfirmed[0]->reason);
        $end = ' / Network disabled, request canceled / audit [--locked])';
        $this->assertStringEndsWith($end, $section->unconfirmed[0]->reason);
        $this->assertSame('npm audit の出力が JSON として読めない(標準エラーが空)', $section->unconfirmed[1]->reason);
    }

    public function test_npm_ci_が失敗したら_2_だけ確認できなかったにして_outdated_は流さない(): void
    {
        $runner = $this->npmRunner('{}', 1);

        $section = $this->npmCheck($runner)->run();

        $this->assertSame([], $section->rows);
        $this->assertStringContainsString('npm ci に失敗した', $section->unconfirmed[0]->reason);
        $this->assertCount(1, $runner->calls);
    }

    public function test_npm_ci_は一時のディレクトリで流し_作業木を触らず_終わったら消す(): void
    {
        $runner = $this->npmRunner('{}');

        $section = $this->npmCheck($runner, self::root())->run();

        $this->assertSame([], $section->unconfirmed);
        $this->assertCount(2, $runner->cwds);
        $this->assertSame($runner->cwds[0], $runner->cwds[1]);
        $this->assertNotSame(self::root(), $runner->cwds[0]);
        $this->assertStringStartsWith($this->temp.'/updcheck-npm-', $runner->cwds[0]);
        $this->assertSame([], glob($this->temp.'/*'));
    }

    public function test_npm_ci_が途中で例外になっても一時のディレクトリを消す(): void
    {
        $this->expectErrorLog();

        $runner = new FakeCommandRunner(['npm ci --ignore-scripts' => new \RuntimeException('boom')]);

        $section = $this->npmCheck($runner, self::root())->run();

        $this->assertCount(1, $section->unconfirmed);
        $this->assertStringStartsWith($this->temp.'/updcheck-npm-', $runner->cwds[0]);
        $this->assertSame([], glob($this->temp.'/*'));
    }

    public function test_npm_outdated_は_latest_の無い行を捨てずに確認できなかったにする(): void
    {
        $json = (string) json_encode(['lodash' => ['current' => '1.0.0', 'wanted' => '1.1.0'], 'ok' => ['current' => '1.0.0', 'wanted' => '1.0.0', 'latest' => '2.0.0']]);

        $section = $this->npmCheck($this->npmRunner($json))->run();

        $this->assertSame([], $section->rows);
        $this->assertStringContainsString('latest', $section->unconfirmed[0]->reason);
    }

    public function test_npm_outdated_は表でない行を確認できなかったにする(): void
    {
        $section = $this->npmCheck($this->npmRunner('{"lodash":"x"}'))->run();

        $this->assertCount(1, $section->unconfirmed);
    }

    public function test_composer_outdated_は必須の鍵が無い行と表でない行を確認できなかったにする(): void
    {
        foreach ([['locked' => [['name' => 'a/b', 'version' => '1']]], ['locked' => ['text']]] as $body) {
            $section = (new ComposerOutdatedCheck($this->runner(self::COMPOSER_OUTDATED, (string) json_encode($body)), '/x'))->run();

            $this->assertSame([], $section->rows);
            $this->assertCount(1, $section->unconfirmed);
        }
    }

    public function test_audit_は表でない脆弱性の要素と名前の無い_npm_の項目を確認できなかったにする(): void
    {
        $runner = new FakeCommandRunner([
            self::COMPOSER_AUDIT => new CommandResult(1, '{"advisories":{"a/b":["text"]},"abandoned":[]}', ''),
            'npm audit --json' => new CommandResult(1, '{"vulnerabilities":{"x":{"severity":"high"}}}', ''),
        ]);

        $section = (new AuditCheck($runner, '/x'))->run();

        $this->assertSame([], $section->rows);
        $this->assertCount(2, $section->unconfirmed);
    }

    public function test_想定外の例外もその項目だけ確認できなかったにして残りを続ける(): void
    {
        $this->expectErrorLog();

        $runner = new FakeCommandRunner([
            self::COMPOSER_AUDIT => new \RuntimeException('boom'),
            'npm audit --json' => new CommandResult(0, '{"vulnerabilities":{}}', ''),
        ]);

        $section = (new AuditCheck($runner, '/x'))->run();

        $this->assertCount(1, $section->unconfirmed);
        $this->assertSame('composer audit', $section->unconfirmed[0]->subject);
        $this->assertStringContainsString('RuntimeException', $section->unconfirmed[0]->reason);
        $this->assertStringContainsString('CommandChecksTest.php', $section->unconfirmed[0]->reason);
        // スタックトレースは error_log() に残る(コメントには種類・文・場所だけ)
        $this->assertStringContainsString('想定外の失敗(composer audit)', $this->errorLog());
        $this->assertStringContainsString('#0', $this->errorLog());
        $this->assertStringNotContainsString('#0', $section->unconfirmed[0]->reason);
    }
}
