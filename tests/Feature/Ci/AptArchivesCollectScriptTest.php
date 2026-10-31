<?php

namespace Tests\Feature\Ci;

use Tests\Support\FakesAptTools;
use Tests\TestCase;

/**
 * 写しの Actions の apt の控えの道具 scripts/ci/apt-archives の collect が、dpkg に入っている版で署名された索引と合う .deb だけを控えに写し、
 * 控えるものが無い回・道具の失敗を分けることを、偽の apt-cache・dpkg-query で確かめる(docs の 設計/コード構成/検査/一括検査の段.md 6.5)。
 * verify は {@see AptArchivesScriptTest}。本物の apt・dpkg は使わない。DB を使わない。道具は {@see FakesAptTools}。
 */
final class AptArchivesCollectScriptTest extends TestCase
{
    use FakesAptTools;

    public function test_collect_copies_only_the_installed_versions_that_match_the_index(): void
    {
        $expected = $this->collectScenario();

        $result = $this->apt(['collect', $this->aptDirectory, $this->archive]);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertSame(
            ['bash_5.2.21-2_amd64.deb', 'fonts-liberation_1%3a2.1.5-3_all.deb'],
            $this->files($this->archive),
        );
        $this->assertStringContainsString('apt の控え: 集めた 2・外した 1(うち記録なし 0)・入っていない版 3', $result->stderr);
        // apt の置き場の .deb は消さない
        $this->assertCount(6, glob($this->aptDirectory.'/*.deb') ?: []);
        $this->assertSame($expected, trim($result->stdout));
    }

    public function test_collect_prints_only_the_key_on_standard_output_and_it_follows_the_content(): void
    {
        $this->collectScenario();

        $first = $this->apt(['collect', $this->aptDirectory, $this->archive]);
        $second = $this->apt(['collect', $this->aptDirectory, $this->archive]);

        $this->assertMatchesRegularExpression('/\A[0-9a-f]{16}\n\z/', $first->stdout);
        $this->assertSame($first->stdout, $second->stdout);

        // 1 つの .deb の中身が変わる(版が進んで索引も変わった)と、鍵が変わる
        $sha = $this->deb($this->aptDirectory, 'bash', '5.2.21-2', 'amd64', 'bash rebuilt');
        $this->index('bash', 'amd64', '5.2.21-2', $sha);
        $changed = $this->apt(['collect', $this->aptDirectory, $this->archive]);

        $this->assertSame(0, $changed->exitCode, $changed->describe());
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{16}\n\z/', $changed->stdout);
        $this->assertNotSame($first->stdout, $changed->stdout);
    }

    public function test_collect_empties_the_archive_first_and_ignores_what_is_not_a_deb_in_the_apt_directory(): void
    {
        $this->collectScenario();
        file_put_contents($this->archive.'/stale_1.0_amd64.deb', 'stale');
        file_put_contents($this->aptDirectory.'/lock', '');
        symlink($this->aptDirectory.'/bash_5.2.21-2_amd64.deb', $this->aptDirectory.'/link_1.0_amd64.deb');

        $result = $this->apt(['collect', $this->aptDirectory, $this->archive]);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertSame(
            ['bash_5.2.21-2_amd64.deb', 'fonts-liberation_1%3a2.1.5-3_all.deb'],
            $this->files($this->archive),
        );
    }

    public function test_collect_with_nothing_to_keep_prints_no_key_and_ends_with_zero(): void
    {
        $sha = $this->deb($this->aptDirectory, 'bash', '5.2', 'amd64', 'bash body');
        $this->index('bash', 'amd64', '5.2', $sha);
        $this->installed([['zlib1g:amd64', '1.3', 'ii ']]);

        $outside = $this->apt(['collect', $this->aptDirectory, $this->archive]);
        $inside = $this->apt(['collect', $this->aptDirectory, $this->archive], githubActions: true);

        $this->assertSame(0, $outside->exitCode, $outside->describe());
        $this->assertSame('', $outside->stdout);
        $this->assertSame([], $this->files($this->archive));
        $this->assertStringNotContainsString('::warning', $outside->stderr);
        $this->assertSame('', $inside->stdout);
        $this->assertStringContainsString("\n::warning title=apt の控え::控えるものが無い", "\n".$inside->stderr);
        $this->assertStringNotContainsString('索引の SHA256 と違う', $inside->stderr);
    }

    public function test_a_failing_or_empty_dpkg_query_is_a_tool_failure(): void
    {
        $this->collectScenario();

        $this->installed([['bash:amd64', '5.2.21-2', 'ii ']], exitCode: 1);
        $failing = $this->apt(['collect', $this->aptDirectory, $this->archive]);

        $this->installed([]);
        $empty = $this->apt(['collect', $this->aptDirectory, $this->archive]);

        $this->assertSame(1, $failing->exitCode, $failing->describe());
        $this->assertStringContainsString('dpkg-query の全件を読めない', $failing->stderr);
        $this->assertSame('', $failing->stdout);
        $this->assertSame(1, $empty->exitCode, $empty->describe());
        $this->assertSame('', $empty->stdout);
    }

    public function test_a_deb_with_no_record_is_counted_but_is_not_an_annotation(): void
    {
        $sha = $this->deb($this->aptDirectory, 'bash', '5.2', 'amd64', 'bash body');
        $this->deb($this->aptDirectory, 'bumped', '1.0', 'amd64', 'bumped body');
        $this->index('bash', 'amd64', '5.2', $sha);
        $this->installed([['bash:amd64', '5.2', 'ii '], ['bumped:amd64', '1.0', 'ii ']]);

        $outside = $this->apt(['collect', $this->aptDirectory, $this->archive]);
        $inside = $this->apt(['collect', $this->aptDirectory, $this->archive], githubActions: true);

        $this->assertSame(0, $inside->exitCode, $inside->describe());
        $this->assertStringContainsString('集めた 1・外した 1(うち記録なし 1)', $outside->stderr);
        $this->assertStringContainsString('集めた 1・外した 1(うち記録なし 1)', $inside->stderr);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{16}\n\z/', $inside->stdout);
        $this->assertStringNotContainsString('::warning', $inside->stderr);
    }

    public function test_a_mismatch_and_a_bad_name_are_both_in_the_annotation_when_something_is_collected(): void
    {
        $this->collectScenario();
        file_put_contents($this->aptDirectory.'/Upper_1.0_amd64.deb', 'x');

        $outside = $this->apt(['collect', $this->aptDirectory, $this->archive]);
        $inside = $this->apt(['collect', $this->aptDirectory, $this->archive], githubActions: true);

        $this->assertStringContainsString('集めた 2・外した 2(うち記録なし 0)', $outside->stderr);
        $this->assertStringNotContainsString('::warning', $outside->stderr);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{16}\n\z/', $inside->stdout);
        $this->assertStringContainsString($this->unexpectedWarning(1, 1, '集めた 2 個'), "\n".$inside->stderr);
        $this->assertStringNotContainsString('控えるものが無い', $inside->stderr);
    }

    /**
     * 置き場に 6 つの .deb を置き、dpkg の status と索引を整える。控えに入るのは bash・fonts-liberation(エポックつきの版)の 2 つ。
     * 外すのは索引と合わない zlib1g の 1 つ、入っていない版は rc の行の old・dpkg に無い notinstalled・別の版が入っている libc6 の 3 つ。
     * 戻り値 = 控えに入る 2 つから決まる鍵。
     */
    private function collectScenario(): string
    {
        $bash = $this->deb($this->aptDirectory, 'bash', '5.2.21-2', 'amd64', 'bash body');
        $fonts = $this->deb($this->aptDirectory, 'fonts-liberation', '1:2.1.5-3', 'all', 'font body');
        $this->deb($this->aptDirectory, 'zlib1g', '1.3', 'amd64', 'zlib old build');
        $old = $this->deb($this->aptDirectory, 'old', '1.0', 'all', 'old body');
        $notInstalled = $this->deb($this->aptDirectory, 'notinstalled', '1.0', 'amd64', 'not installed body');
        $libc = $this->deb($this->aptDirectory, 'libc6', '2.39-1', 'amd64', 'libc body');

        $this->index('bash', 'amd64', '5.2.21-2', $bash);
        $this->index('fonts-liberation', 'all', '1:2.1.5-3', $fonts);
        $this->index('zlib1g', 'amd64', '1.3', hash('sha256', 'zlib signed build'));
        $this->index('old', 'all', '1.0', $old);
        $this->index('notinstalled', 'amd64', '1.0', $notInstalled);
        $this->index('libc6', 'amd64', '2.39-1', $libc);
        $this->installed([
            ['bash:amd64', '5.2.21-2', 'ii '],
            ['fonts-liberation:all', '1:2.1.5-3', 'ii '],
            ['zlib1g:amd64', '1.3', 'ii '],
            ['old:all', '1.0', 'rc '],
            ['libc6:amd64', '2.39-2', 'ii '],
        ]);

        return substr(hash('sha256', "bash:amd64 {$bash}\nfonts-liberation:all {$fonts}\n"), 0, 16);
    }
}
