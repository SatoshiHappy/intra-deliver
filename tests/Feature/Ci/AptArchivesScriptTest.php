<?php

namespace Tests\Feature\Ci;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakesAptTools;
use Tests\TestCase;

/**
 * 写しの Actions の apt の控えの道具 scripts/ci/apt-archives の verify が、署名された索引(apt-cache show の SHA256)と合う .deb だけを控えに残し、
 * 記録なし・道具の失敗・引数の誤りを分けることを、偽の apt-cache・dpkg-query で確かめる(docs の 設計/コード構成/検査/一括検査の段.md 6.5)。
 * collect は {@see AptArchivesCollectScriptTest}。本物の apt・dpkg は使わない。DB を使わない。道具は {@see FakesAptTools}。
 */
final class AptArchivesScriptTest extends TestCase
{
    use FakesAptTools;

    public function test_verify_keeps_a_deb_whose_sha256_is_in_the_signed_index(): void
    {
        $sha = $this->deb($this->archive, 'bash', '5.2.21-2', 'amd64', 'bash body');
        $this->index('bash', 'amd64', '5.2.21-2', $sha);

        $result = $this->apt(['verify', $this->archive]);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertSame(['bash_5.2.21-2_amd64.deb'], $this->files($this->archive));
        $this->assertStringContainsString('apt の控え: 残した 1・外した 0(うち記録なし 0)', $result->stderr);
        $this->assertSame('', $result->stdout);
        $this->assertSame(['apt-cache policy', 'apt-cache show bash:amd64=5.2.21-2'], $this->calls());
    }

    public function test_verify_removes_a_deb_with_the_same_version_but_a_different_sha256(): void
    {
        $this->deb($this->archive, 'bash', '5.2.21-2', 'amd64', 'old build');
        $this->index('bash', 'amd64', '5.2.21-2', hash('sha256', 'the signed build'));

        $result = $this->apt(['verify', $this->archive]);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertSame([], $this->files($this->archive));
        $this->assertStringContainsString('apt の控え: 残した 0・外した 1(うち記録なし 0)', $result->stderr);
        $this->assertStringContainsString('署名された索引の SHA256 と違う', $result->stderr);
    }

    public function test_verify_removes_a_deb_whose_name_is_not_in_the_index_and_still_ends_with_zero(): void
    {
        // 名前が索引に無いと apt-cache show は 100 で終わる(終了コードだけでは道具の失敗と分けられない)
        $this->deb($this->archive, 'gone', '1.0', 'amd64', 'gone body');

        $result = $this->apt(['verify', $this->archive]);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertSame([], $this->files($this->archive));
        $this->assertStringContainsString('外した 1(うち記録なし 1)', $result->stderr);
        $this->assertStringContainsString('署名された索引に記録が無い', $result->stderr);
    }

    public function test_verify_removes_a_deb_when_the_index_has_the_name_but_not_the_version(): void
    {
        // 名前はあり版が索引に無いと、apt-cache show は 0 で空を返す
        $this->deb($this->archive, 'libc6', '9.9', 'amd64', 'libc body');
        $this->indexRaw('libc6:amd64=9.9', '');

        $result = $this->apt(['verify', $this->archive]);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertSame([], $this->files($this->archive));
        $this->assertStringContainsString('外した 1(うち記録なし 1)', $result->stderr);
    }

    public function test_verify_removes_a_deb_whose_only_record_is_made_from_the_dpkg_status(): void
    {
        // 入った版が索引から消えていると、apt-cache show は SHA256 の無い status の記録を 0 で返す
        $this->deb($this->archive, 'apparmor', '4.0.1', 'amd64', 'apparmor body');
        $this->indexRaw('apparmor:amd64=4.0.1', "Package: apparmor\nStatus: install ok installed\nVersion: 4.0.1\n");

        $result = $this->apt(['verify', $this->archive]);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertSame([], $this->files($this->archive));
        $this->assertStringContainsString('外した 1(うち記録なし 1)', $result->stderr);
    }

    public function test_verify_asks_the_index_for_the_architecture_of_the_file(): void
    {
        $sha = $this->deb($this->archive, 'foo', '1.0', 'amd64', 'same body');
        $this->deb($this->archive, 'foo', '1.0', 'all', 'same body');
        $this->index('foo', 'amd64', '1.0', $sha);
        $this->index('foo', 'all', '1.0', hash('sha256', 'the all build'));

        $result = $this->apt(['verify', $this->archive]);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertSame(['foo_1.0_amd64.deb'], $this->files($this->archive));
        $this->assertContains('apt-cache show foo:amd64=1.0', $this->calls());
        $this->assertContains('apt-cache show foo:all=1.0', $this->calls());
    }

    public function test_verify_restores_the_epoch_colon_of_the_version(): void
    {
        $sha = $this->deb($this->archive, 'fonts-liberation', '1:2.1.5-3', 'all', 'font body');
        $this->index('fonts-liberation', 'all', '1:2.1.5-3', $sha);

        $result = $this->apt(['verify', $this->archive]);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertSame(['fonts-liberation_1%3a2.1.5-3_all.deb'], $this->files($this->archive));
        $this->assertContains('apt-cache show fonts-liberation:all=1:2.1.5-3', $this->calls());
    }

    public function test_verify_removes_symbolic_links_and_names_outside_the_form_without_asking_the_index(): void
    {
        $sha = $this->deb($this->root, 'real', '1.0', 'amd64', 'real body');
        $this->index('real', 'amd64', '1.0', $sha);
        symlink($this->root.'/real_1.0_amd64.deb', $this->archive.'/real_1.0_amd64.deb');
        $outsideTheForm = [
            'Upper_1.0_amd64.deb', '-opt_1.0_amd64.deb', 'name_1.0;x_amd64.deb', 'name_1.0.deb', 'notes.txt',
        ];
        foreach ($outsideTheForm as $file) {
            file_put_contents($this->archive.'/'.$file, 'x');
        }
        mkdir($this->archive.'/dir.deb');

        $result = $this->apt(['verify', $this->archive]);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertSame([], $this->files($this->archive));
        // リンク先は消さない
        $this->assertFileExists($this->root.'/real_1.0_amd64.deb');
        $this->assertSame(['apt-cache policy'], $this->calls());
        $this->assertStringContainsString('apt の控え: 残した 0・外した 7(うち記録なし 0)', $result->stderr);
    }

    public function test_verify_treats_a_missing_archive_as_empty(): void
    {
        $result = $this->apt(['verify', $this->root.'/none']);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertStringContainsString('apt の控え: 残した 0・外した 0(うち記録なし 0)', $result->stderr);
    }

    public function test_a_failing_apt_cache_policy_is_a_tool_failure_and_leaves_the_archive_alone(): void
    {
        $this->installAptTools(policyExitCode: 100);
        $sha = $this->deb($this->archive, 'bash', '5.2', 'amd64', 'bash body');
        $this->index('bash', 'amd64', '5.2', $sha);

        $verify = $this->apt(['verify', $this->archive]);
        $collect = $this->apt(['collect', $this->aptDirectory, $this->root.'/new']);

        $this->assertSame(1, $verify->exitCode, $verify->describe());
        $this->assertStringContainsString('apt-cache policy が失敗した', $verify->stderr);
        $this->assertSame(['bash_5.2_amd64.deb'], $this->files($this->archive));
        $this->assertSame(1, $collect->exitCode, $collect->describe());
        $this->assertSame('', $collect->stdout);
    }

    /**
     * @param  list<string>  $arguments
     */
    #[DataProvider('wrongArguments')]
    public function test_wrong_arguments_are_a_usage_error(array $arguments): void
    {
        $result = $this->runCi('apt-archives', $arguments, ['GITHUB_ACTIONS' => '']);

        $this->assertSame(2, $result->exitCode, $result->describe());
        $this->assertStringContainsString('使い方: scripts/ci/apt-archives verify <控え> | collect <', $result->stderr);
        $this->assertSame([], $this->calls());
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function wrongArguments(): array
    {
        return [
            '引数なし' => [[]],
            '知らない動作' => [['clean', 'a']],
            'verify の引数が無い' => [['verify']],
            'verify の引数が多い' => [['verify', 'a', 'b']],
            'collect の引数が足りない' => [['collect', 'a']],
            'collect の引数が多い' => [['collect', 'a', 'b', 'c']],
        ];
    }
}
