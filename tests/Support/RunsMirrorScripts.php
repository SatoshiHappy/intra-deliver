<?php

namespace Tests\Support;

use Symfony\Component\Process\ExecutableFinder;
use Tests\Architecture\Engine\Process;
use Tests\Architecture\Engine\ProcessResult;
use Tests\Architecture\Engine\TempDirectory;

/**
 * 写しへの push scripts/mirror/push を、ローカルの bare リポジトリを写しにして流すテストが使う共通の道具。DB・ネットワークを使わない。
 * 使うクラスは Tests\TestCase を継承する({@see RunsDeployScripts} と同じ形)。
 *
 * 一時の場所(build/fixtures の下)に、スクリプトを写して git でコミットした元の作業木(work)と、写し役の bare リポジトリ(mirror)を作る。
 * スクリプトは環境変数 MIRROR_URL に mirror を渡して流すので、本物の写しには届かない。
 * 秘匿情報の検査に使う gitleaks は、引数と検査した場所の中身を記録する偽物(fakeGitleaks)を環境変数 MIRROR_GITLEAKS で渡す(本物は取りにいかない)。
 * 一時ディレクトリは TMPDIR を一時の場所の下に向けて、残らないことを照合できるようにする。
 * 元のコミットの作者のメールは noreply と違う値にして、写しへ漏れたら見分けられるようにする。
 */
trait RunsMirrorScripts
{
    protected const MIRROR_EMAIL = '93377366+SatoshiHappy@users.noreply.github.com';

    protected const SOURCE_EMAIL = 'private-author@example.test';

    protected string $root;

    protected string $work;

    protected string $mirror;

    protected string $tmp;

    protected string $gitleaksLog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = (string) realpath(TempDirectory::create(base_path('build/fixtures'), 'mirror-script'));
        $this->work = $this->root.'/work';
        $this->mirror = $this->root.'/mirror.git';
        $this->tmp = $this->root.'/tmp';
        $this->gitleaksLog = $this->root.'/gitleaks.log';
        mkdir($this->work.'/scripts/mirror', 0777, true);
        mkdir($this->tmp);

        copy(base_path('scripts/mirror/push'), $this->work.'/scripts/mirror/push');
        chmod($this->work.'/scripts/mirror/push', 0755);

        $this->execute(['git', 'init', '-q', '-b', 'main'], $this->work);
        $this->git('config', 'user.email', self::SOURCE_EMAIL);
        $this->git('config', 'user.name', 'private author');
        $this->git('config', 'commit.gpgsign', 'false');
        $this->git('add', 'scripts');
        $this->commitFile('first.txt', '非公開の最初のコミット');

        $this->execute(['git', 'init', '-q', '--bare', $this->mirror], $this->root);
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->root);

        parent::tearDown();
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     */
    protected function push(array $arguments = [], ?string $url = null, array $environment = []): ProcessResult
    {
        return Process::run(
            [$this->work.'/scripts/mirror/push', ...$arguments],
            $this->work,
            $environment + [
                'MIRROR_URL' => $url ?? $this->mirror,
                'MIRROR_GITLEAKS' => $this->fakeGitleaks(0),
                'TMPDIR' => $this->tmp,
            ],
        );
    }

    /**
     * 偽の gitleaks を置いてそのパスを返す。呼ばれたら、引数・検査した場所・その中のファイルの一覧を gitleaksLog に書き、$exitCode で終わる。
     */
    protected function fakeGitleaks(int $exitCode): string
    {
        $path = $this->root.'/fake-gitleaks-'.$exitCode;
        $script = <<<'BASH'
#!/usr/bin/env bash
{
    echo "args: $*"
    echo "cwd: $(pwd)"
    find . -type f | sort | sed 's/^/file: /'
} > %s
exit %d
BASH;
        file_put_contents($path, sprintf($script, escapeshellarg($this->gitleaksLog), $exitCode)."\n");
        chmod($path, 0755);

        return $path;
    }

    /**
     * 元の作業木にファイルを書いてコミットし、そのコミットの SHA を返す。
     */
    protected function commitFile(string $name, string $message): string
    {
        file_put_contents($this->work.'/'.$name, $message."\n");
        $this->git('add', $name);
        $this->git('commit', '-q', '-m', $message);

        return $this->sourceHead();
    }

    protected function sourceHead(): string
    {
        return trim($this->git('rev-parse', 'HEAD')->stdout);
    }

    protected function git(string ...$arguments): ProcessResult
    {
        return $this->execute(['git', ...array_values($arguments)], $this->work);
    }

    protected function mirrorGit(string ...$arguments): ProcessResult
    {
        return $this->execute(['git', '-C', $this->mirror, ...array_values($arguments)], $this->root);
    }

    /**
     * 写しの枝の先頭(枝が無ければ null)。
     */
    protected function mirrorHead(string $branch): ?string
    {
        $result = Process::run(
            ['git', '-C', $this->mirror, 'rev-parse', '--verify', '--quiet', 'refs/heads/'.$branch],
            $this->root,
        );

        return $result->exitCode === 0 ? trim($result->stdout) : null;
    }

    /**
     * 写しの全部の枝とその先頭(変わっていないことの照合に使う)。
     */
    protected function mirrorRefs(): string
    {
        return $this->mirrorGit('for-each-ref', '--format=%(refname) %(objectname)')->stdout;
    }

    /**
     * 写しのコミットの 1 つの項目(git log の書式の指定子。例: %P・%T・%an・%ae・%cn・%ce・%s)。
     */
    protected function mirrorCommitField(string $commit, string $format): string
    {
        return trim($this->mirrorGit('show', '-s', '--format='.$format, $commit)->stdout);
    }

    /**
     * 元の作業木のコミットの木。
     */
    protected function sourceTree(string $commit): string
    {
        return trim($this->git('rev-parse', $commit.'^{tree}')->stdout);
    }

    /**
     * git の包みを置いたディレクトリを返す。fetch のときだけ何も書かずに失敗し、ほかは本物の git へ渡す(通信の失敗の再現)。
     */
    protected function gitThatFailsToFetch(): string
    {
        $directory = $this->root.'/failing-fetch-bin';
        mkdir($directory);
        $script = <<<'BASH'
#!/usr/bin/env bash
for argument in "$@"; do
    if [ "${argument}" = fetch ]; then
        exit 1
    fi
done
exec %s "$@"
BASH;
        $realGit = (string) (new ExecutableFinder)->find('git');
        file_put_contents($directory.'/git', sprintf($script, escapeshellarg($realGit))."\n");
        chmod($directory.'/git', 0755);

        return $directory;
    }

    /**
     * @return list<string>
     */
    protected function leftoverTemporaryEntries(): array
    {
        return array_values(array_diff(scandir($this->tmp) ?: [], ['.', '..']));
    }

    /**
     * 成功(終了コード 0)を期待して流す。失敗したら説明つきで落とす。
     *
     * @param  list<string>  $command
     */
    private function execute(array $command, string $cwd): ProcessResult
    {
        $result = Process::run($command, $cwd);
        $this->assertSame(0, $result->exitCode, $result->describe());

        return $result;
    }
}
