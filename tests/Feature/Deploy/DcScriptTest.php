<?php

namespace Tests\Feature\Deploy;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RunsDeployScripts;
use Tests\TestCase;

/**
 * 公開用の呼び出し scripts/deploy/dc の入口の検査(docs の 設計/公開の配備/配備と戻し方.md 6.1〜6.4)を、偽の docker で確かめる。
 * 本物の docker は呼ばない。道具は {@see RunsDeployScripts}。
 */
final class DcScriptTest extends TestCase
{
    use RunsDeployScripts;

    /**
     * @return array<string, array{string}>
     */
    public static function allowedSubcommands(): array
    {
        $names = ['build', 'up', 'run', 'exec', 'logs', 'ps', 'stop', 'start', 'down'];

        return array_combine($names, array_map(fn (string $name) => [$name], $names));
    }

    /**
     * (a) 正常: プロジェクト名・compose ファイル・補間の値のファイルを付けて docker compose に渡し、docker の終了コードで終わる。
     */
    #[DataProvider('allowedSubcommands')]
    public function test_dc_passes_the_arguments_to_docker_compose(string $subcommand): void
    {
        $result = $this->runDc($subcommand, '--flag', 'target');

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertSame(
            [self::PROJECT_ARGUMENTS." -f {$this->work}/compose.publish.yml --env-file {$this->composeEnv()} {$subcommand} --flag target"],
            $this->calls(),
        );
    }

    public function test_dc_ends_with_the_exit_code_of_docker(): void
    {
        $this->installDocker([['ps', 3, 'failed']]);

        $result = $this->runDc('ps');

        $this->assertSame(3, $result->exitCode, $result->describe());
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function rejectedArguments(): array
    {
        return [
            'config' => [['config']],
            'convert' => [['convert']],
            'bridge' => [['bridge', 'convert']],
            'publish' => [['publish', '--with-env', 'name']],
            'restart' => [['restart', 'app']],
            'global option before config' => [['--ansi', 'never', 'config']],
            'global option with a value before config' => [['--profile', 'x', 'config']],
            'global option only' => [['--ansi', 'never']],
        ];
    }

    /**
     * (b) 許可リストに無いサブコマンドと、サブコマンドの前に置いた全体のオプションは、終了 2 で docker を呼ばない。
     *
     * @param  list<string>  $arguments
     */
    #[DataProvider('rejectedArguments')]
    public function test_dc_rejects_what_is_not_an_allowed_subcommand(array $arguments): void
    {
        $result = $this->runDc(...$arguments);

        $this->assertSame(2, $result->exitCode, $result->describe());
        $this->assertSame([], $this->calls());
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function invalidImageTagLines(): array
    {
        return [
            'empty' => [['IMAGE_TAG=']],
            'HEAD' => [['IMAGE_TAG=HEAD']],
            'branch name' => [['IMAGE_TAG=main']],
            'starts with a hyphen' => [['IMAGE_TAG=-abcdef0']],
            'too short' => [['IMAGE_TAG=abcdef']],
            'unresolvable commit' => [['IMAGE_TAG=deadbeefdeadbeef']],
            'no line' => [['PUBLISH_CONFIG_DIR=/path']],
            'key not in the form of a name' => [['export IMAGE_TAG=abcdef0']],
        ];
    }

    /**
     * (c) IMAGE_TAG が git の SHA の形でない・コミットとして解決できない・行が無いときは、終了 2 で docker を呼ばない。
     *
     * @param  list<string>  $lines
     */
    #[DataProvider('invalidImageTagLines')]
    public function test_dc_rejects_an_invalid_image_tag(array $lines): void
    {
        $this->writeComposeEnv(...$lines);

        $result = $this->runDc('ps');

        $this->assertSame(2, $result->exitCode, $result->describe());
        $this->assertSame([], $this->calls());
    }

    /**
     * compose.env の空白だけの行・空行・注釈の行(先頭に空白があるものを含む)は読み飛ばし、通る。
     */
    public function test_dc_skips_blank_and_comment_lines_of_the_compose_env(): void
    {
        $this->writeComposeEnv('# comment', '', '   ', "\t", '  # indented comment', 'IMAGE_TAG='.$this->currentCommit(), 'PUBLISH_CONFIG_DIR=/path/to/config');

        $result = $this->runDc('ps');

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertCount(1, $this->calls());
    }

    /**
     * compose.env の行頭に空白のあるキーは、変数名の形でないので止める(compose と読み方がずれるのを避ける)。
     */
    public function test_dc_rejects_a_key_with_leading_whitespace(): void
    {
        $this->writeComposeEnv('  IMAGE_TAG='.$this->currentCommit());

        $result = $this->runDc('ps');

        $this->assertSame(2, $result->exitCode, $result->describe());
        $this->assertSame([], $this->calls());
    }

    /**
     * (c) IMAGE_TAG の行が 2 つあるときは、どちらも HEAD でも終了 2。
     */
    public function test_dc_rejects_two_image_tag_lines(): void
    {
        $head = $this->currentCommit();
        $this->writeComposeEnv("IMAGE_TAG={$head}", "IMAGE_TAG={$head}");

        $result = $this->runDc('ps');

        $this->assertSame(2, $result->exitCode, $result->describe());
        $this->assertSame([], $this->calls());
    }

    /**
     * (c) 実在するコミットでも、HEAD と違えば終了 2(別の版の作業木から流している)。
     */
    public function test_dc_rejects_a_sha_that_is_not_head(): void
    {
        $first = $this->currentCommit();
        $this->git('commit', '-q', '--allow-empty', '-m', 'second');
        $this->assertNotSame($first, $this->currentCommit());
        $this->writeComposeEnv("IMAGE_TAG={$first}");

        $result = $this->runDc('ps');

        $this->assertSame(2, $result->exitCode, $result->describe());
        $this->assertSame([], $this->calls());
    }

    /**
     * (c) 短い SHA でも HEAD を指していれば通る。
     */
    public function test_dc_accepts_a_short_sha_of_head(): void
    {
        $this->writeComposeEnv('IMAGE_TAG='.substr($this->currentCommit(), 0, 7));

        $result = $this->runDc('ps');

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertCount(1, $this->calls());
    }

    /**
     * (d) 追跡しているファイルの変更・追跡していないファイルがあるときは、終了 2 で docker を呼ばない。
     */
    public function test_dc_rejects_uncommitted_changes(): void
    {
        file_put_contents($this->work.'/compose.publish.yml', "changed\n", FILE_APPEND);
        $modified = $this->runDc('ps');
        $this->git('checkout', '--', 'compose.publish.yml');

        file_put_contents($this->work.'/untracked.txt', "new\n");
        $untracked = $this->runDc('ps');

        $this->assertSame(2, $modified->exitCode, $modified->describe());
        $this->assertSame(2, $untracked->exitCode, $untracked->describe());
        $this->assertSame([], $this->calls());
    }

    /**
     * git が無視するファイル(wrangler の作る .wrangler/ など)は未コミットの変更に数えない。
     */
    public function test_dc_ignores_files_that_git_ignores(): void
    {
        file_put_contents($this->work.'/.gitignore', ".wrangler/\n");
        $this->commitAll();
        $this->writeComposeEnv();
        mkdir($this->work.'/deploy/worker/.wrangler/tmp', 0777, true);
        file_put_contents($this->work.'/deploy/worker/.wrangler/tmp/bundle.js', "x\n");

        $result = $this->runDc('ps');

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertCount(1, $this->calls());
    }

    /**
     * (e) シェルに IMAGE_TAG(と compose.publish.yml だけに現れる補間の変数)を置いても、検査は compose.env の値で行い、
     * docker に渡る環境にはどちらも無い(シェルの値が compose.env より優先されるのを防ぐ)。
     */
    public function test_dc_checks_with_the_compose_env_and_removes_the_variables_from_the_shell(): void
    {
        $result = $this->runDcWithShell(['ps'], ['IMAGE_TAG' => 'other', 'ONLY_IN_THE_COMPOSE_FILE' => 'other']);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertSame(['IMAGE_TAG=unset ONLY_IN_THE_COMPOSE_FILE=unset'], $this->environmentSeenByDocker());
    }
}
