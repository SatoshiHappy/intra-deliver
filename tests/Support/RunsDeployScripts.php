<?php

namespace Tests\Support;

use Tests\Architecture\Engine\Process;
use Tests\Architecture\Engine\ProcessResult;
use Tests\Architecture\Engine\TempDirectory;
use UnexpectedValueException;

/**
 * 公開用のスクリプト(scripts/deploy/dc・migrate-and-seed)を、偽の docker で流すテストが使う共通の道具。DB を使わない。
 *
 * 一時の場所(build/fixtures の下)に、2 本のスクリプトを写して git でコミットした作業木(work)と、作業木の外の道具の置き場(tools)を作る。
 * tools には偽の docker(受け取った引数を calls.log に 1 行ずつ書き、決めた終了コードと出力で終わる)・compose.env を置く。作業木の中に置くと
 * 未コミットの変更の検査で止まるため。偽の docker は PATH の先頭に置いて流す。使うクラスは Tests\TestCase を継承する。
 */
trait RunsDeployScripts
{
    protected const PROJECT_ARGUMENTS = 'compose -p intra-deliver-portfolio-publish';

    protected string $root;

    protected string $work;

    protected string $tools;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = (string) realpath(TempDirectory::create(base_path('build/fixtures'), 'deploy-scripts'));
        $this->work = $this->root.'/work';
        $this->tools = $this->root.'/tools';
        mkdir($this->work.'/scripts/deploy', 0777, true);
        mkdir($this->tools, 0777, true);

        foreach (['dc', 'migrate-and-seed'] as $script) {
            $path = $this->work.'/scripts/deploy/'.$script;
            copy(base_path('scripts/deploy/'.$script), $path);
            chmod($path, 0755);
        }

        file_put_contents($this->work.'/compose.publish.yml', "services:\n  app:\n    image: app:\${ONLY_IN_THE_COMPOSE_FILE:?required}\n");

        $this->git('init', '-q');
        $this->git('config', 'user.email', 'test@example.com');
        $this->git('config', 'user.name', 'test');
        $this->git('config', 'commit.gpgsign', 'false');
        $this->commitAll();

        $this->installDocker();
        $this->writeComposeEnv();
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->root);

        parent::tearDown();
    }

    protected function runDc(string ...$arguments): ProcessResult
    {
        return $this->runScript('dc', [$this->composeEnv(), ...array_values($arguments)]);
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $shellEnvironment
     */
    protected function runDcWithShell(array $arguments, array $shellEnvironment): ProcessResult
    {
        return $this->runScript('dc', [$this->composeEnv(), ...$arguments], $shellEnvironment);
    }

    protected function runMigrateAndSeed(): ProcessResult
    {
        return $this->runScript('migrate-and-seed', [$this->composeEnv()]);
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $shellEnvironment
     */
    protected function runScript(string $script, array $arguments, array $shellEnvironment = []): ProcessResult
    {
        return Process::run(
            [$this->work.'/scripts/deploy/'.$script, ...$arguments],
            $this->work,
            ['PATH' => $this->tools.':'.getenv('PATH')] + $shellEnvironment,
        );
    }

    protected function composeEnv(): string
    {
        return $this->tools.'/compose.env';
    }

    /**
     * compose.env を書く。行を渡さなければ、HEAD の SHA と設定ディレクトリの 2 行。
     */
    protected function writeComposeEnv(string ...$lines): void
    {
        $lines = $lines === [] ? ['IMAGE_TAG='.$this->currentCommit(), 'PUBLISH_CONFIG_DIR=/path/to/config'] : $lines;

        file_put_contents($this->composeEnv(), implode("\n", $lines)."\n");
    }

    /**
     * 偽の docker を置く。$rules の各行は [引数に含まれる文字列, 終了コード, 出力]。先に当たった行が使われ、当たらなければ成功で終わる。
     *
     * @param  list<array{string, int, string}>  $rules
     */
    protected function installDocker(array $rules = []): void
    {
        $cases = '';
        foreach ($rules as [$needle, $exitCode, $output]) {
            $cases .= sprintf("    *%s*) printf '%%s\\n' %s; exit %d ;;\n", escapeshellarg($needle), escapeshellarg($output), $exitCode);
        }

        $script = "#!/bin/sh\n"
            .sprintf("printf '%%s\\n' \"\$*\" >> %s\n", escapeshellarg($this->tools.'/calls.log'))
            .sprintf(
                "if [ -n \"\${IMAGE_TAG+x}\" ]; then a=set; else a=unset; fi\nif [ -n \"\${ONLY_IN_THE_COMPOSE_FILE+x}\" ]; then b=set; else b=unset; fi\necho \"IMAGE_TAG=\$a ONLY_IN_THE_COMPOSE_FILE=\$b\" >> %s\n",
                escapeshellarg($this->tools.'/environment.log'),
            )
            ."case \"\$*\" in\n{$cases}esac\nexit 0\n";

        file_put_contents($this->tools.'/docker', $script);
        chmod($this->tools.'/docker', 0755);
    }

    /**
     * 偽の docker が受け取った引数(1 呼び出し 1 行)。
     *
     * @return list<string>
     */
    protected function calls(): array
    {
        $path = $this->tools.'/calls.log';

        return $this->readLines($path);
    }

    /**
     * 偽の docker が見た環境(1 呼び出し 1 行。値は書かず、あるかどうかだけ)。
     *
     * @return list<string>
     */
    protected function environmentSeenByDocker(): array
    {
        $path = $this->tools.'/environment.log';

        return $this->readLines($path);
    }

    /**
     * 呼び出しのうち、docker compose に付ける共通の引数(-p -f --env-file)より後ろ。
     *
     * @return list<string>
     */
    protected function subcommandsCalled(): array
    {
        $prefix = self::PROJECT_ARGUMENTS." -f {$this->work}/compose.publish.yml --env-file {$this->composeEnv()} ";

        return array_map(function (string $call) use ($prefix) {
            $this->assertStringStartsWith($prefix, $call);

            return substr($call, strlen($prefix));
        }, $this->calls());
    }

    protected function currentCommit(): string
    {
        return trim($this->git('rev-parse', 'HEAD')->stdout);
    }

    protected function commitAll(): void
    {
        $this->git('add', '--force', '.');
        $this->git('commit', '-q', '-m', 'commit');
    }

    protected function git(string ...$arguments): ProcessResult
    {
        $result = Process::run(['git', ...array_values($arguments)], $this->work);
        $this->assertSame(0, $result->exitCode, $result->describe());

        return $result;
    }

    /**
     * 記録のファイルの行(ファイルが無ければ空)。読めなければ例外。
     *
     * @return list<string>
     */
    protected function readLines(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new UnexpectedValueException("記録のファイルを読めない: {$path}");
        }

        return $lines;
    }
}
