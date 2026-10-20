<?php

namespace Tests\Architecture;

use Tests\Architecture\Engine\Finding;
use Tests\Architecture\Engine\Findings;
use Tests\Architecture\Engine\Process;
use Tests\Architecture\Engine\ProcessResult;
use Tests\Architecture\Engine\TempDirectory;
use Tests\Architecture\Policy\Verification;

/**
 * K 秘匿情報(#11)の自己証明。scripts/ci/secrets と同じ gitleaks バイナリ・
 * 同じ設定(履歴の走査 = .gitleaks.toml、作業木の走査 = .gitleaks-dir.toml)・同じ作業ディレクトリ(アプリのルート。
 * .gitleaks-dir.toml の [extend] の path はプロセスの作業ディレクトリ基点で解決される)を使い、走査ルートだけを
 * 一時 git リポジトリ(履歴)か一時ディレクトリ(作業木)に差し替える。
 *
 * #11 の fixture は通常の走査除外(台帳⑧)を掛けない(fixture は秘匿情報検査から除外しない)ため、
 * commit せず build/ の下に一時リポジトリを作ってテストのたびに作り、終わったら消す。
 * 流用元 = chatpinet_2 の同名のテスト(バイナリを scripts/ci/gitleaks で用意する処理と git の失敗の検出を足し、作業木の走査ルートを絶対パスにした)。
 */
final class SecretsSelfProofTest extends SelfProofTestCase
{
    private const RULE_ID = 'aws-access-token';

    // 実在しない偽の鍵。gitleaks 既定規則 aws-access-token の正規表現(AKIA + [A-Z2-7]{16})に一致させるためだけの文字列。
    // このテストファイル自身が gitleaks dir(作業木)の対象なので、2 つに割って持ち、連結した値だけを
    // fixture のファイルに書く(このソースファイルの生バイトには 20 文字が連続して出現しない)
    private const FAKE_SECRET_PREFIX = 'AKIA';

    private const FAKE_SECRET_SUFFIX = 'ABCDEFGHIJKLMNOP';

    private string $appRoot;

    private string $workRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->appRoot = dirname(__DIR__, 2);
        $this->workRoot = TempDirectory::create($this->appRoot.'/build/fixtures', 'secrets');

        // scripts/ci/secrets と同じバイナリを用意する(T は K より前に走るので、ここで無ければ取得する)
        $install = Process::run([$this->appRoot.'/scripts/ci/gitleaks'], $this->appRoot);
        $this->assertSame(0, $install->exitCode, "gitleaks のバイナリを用意できない\n".$install->describe());
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->workRoot);

        parent::tearDown();
    }

    public function test_detects_a_committed_fake_secret(): void
    {
        $repository = $this->initRepository('violating');
        file_put_contents($repository.'/config.txt', 'aws_key = '.self::fakeSecret().PHP_EOL);
        $this->commitAll($repository, 'add fake secret');

        $this->assertDetects(self::RULE_ID, 'config.txt', 1, $this->runGitleaksGit($repository));
    }

    public function test_reports_clean_for_a_repository_without_secrets(): void
    {
        $repository = $this->initRepository('passing');
        file_put_contents($repository.'/config.txt', 'app_name = intra-deliver'.PHP_EOL);
        $this->commitAll($repository, 'add harmless file');

        $this->assertClean($this->runGitleaksGit($repository));
    }

    public function test_history_scan_detects_a_committed_env_file(): void
    {
        $repository = $this->initRepository('violating-env');
        file_put_contents($repository.'/.env', 'AWS_ACCESS_KEY_ID='.self::fakeSecret().PHP_EOL);
        $this->commitAll($repository, 'add env file');

        $this->assertDetects(self::RULE_ID, '.env', 1, $this->runGitleaksGit($repository));
    }

    public function test_working_tree_scan_detects_a_plain_file_and_ignores_an_env_file(): void
    {
        $directory = $this->workRoot.'/working-tree';
        mkdir($directory, 0777, true);
        file_put_contents($directory.'/config.txt', 'aws_key = '.self::fakeSecret().PHP_EOL);
        file_put_contents($directory.'/.env', 'AWS_ACCESS_KEY_ID='.self::fakeSecret().PHP_EOL);

        // 走査ルートは絶対パスで渡す(アプリのルート起点の相対パス build/… だと、走査ルート基点の .gitleaks.toml の build の除外
        // ^build(?:/.*)?$ に一致して、偽の鍵ごと外れる。.env の除外は (?:^|/) で始まるので絶対パスにも効く)
        $result = $this->runGitleaks('dir', $directory, Verification::GITLEAKS_DIR_CONFIG);
        $envFindings = array_filter($this->findings($result), fn (Finding $finding): bool => basename($finding->file) === '.env');

        $this->assertDetects(self::RULE_ID, 'config.txt', 1, $result);
        $this->assertSame([], $envFindings, "作業木の走査が .env を検出した\n".$result->describe());
    }

    protected function findings(ProcessResult $result): array
    {
        return Findings::fromJson($result->stdout, 'RuleID', 'File', 'StartLine');
    }

    private static function fakeSecret(): string
    {
        return self::FAKE_SECRET_PREFIX.self::FAKE_SECRET_SUFFIX;
    }

    private function initRepository(string $name): string
    {
        $path = $this->workRoot.'/'.$name;
        mkdir($path, 0777, true);

        $this->git(['init', '--quiet'], $path);
        $this->git(['config', 'user.email', 'selfproof@example.test'], $path);
        $this->git(['config', 'user.name', 'SelfProof'], $path);

        return $path;
    }

    private function commitAll(string $repository, string $message): void
    {
        // 利用者の global な ignore(.env を無視する設定など)に左右されないよう、強制して追加する
        $this->git(['add', '--force', '.'], $repository);
        $this->git(['commit', '--quiet', '-m', $message], $repository);
    }

    /**
     * git を実行し、失敗したらその出力でテストを止める(fixture の履歴を作れないまま検査を走らせない)。
     *
     * @param  list<string>  $arguments
     */
    private function git(array $arguments, string $cwd): void
    {
        $result = Process::run(['git', ...$arguments], $cwd);

        $this->assertSame(0, $result->exitCode, "fixture の git の操作が失敗した\n".$result->describe());
    }

    private function runGitleaksGit(string $repository): ProcessResult
    {
        return $this->runGitleaks('git', $repository, Verification::GITLEAKS_GIT_CONFIG);
    }

    /**
     * scripts/ci/secrets と同じく、アプリのルートを作業ディレクトリにして gitleaks を走らせる。
     *
     * @param  string  $config  設定ファイル(アプリのルート起点)
     */
    private function runGitleaks(string $command, string $target, string $config): ProcessResult
    {
        return Process::run([
            $this->appRoot.'/build/bin/gitleaks',
            $command,
            $target,
            '--config', $config,
            '--redact',
            '--no-banner',
            '--exit-code', '1',
            '--report-format', 'json',
            '--report-path', '-',
        ], $this->appRoot);
    }
}
