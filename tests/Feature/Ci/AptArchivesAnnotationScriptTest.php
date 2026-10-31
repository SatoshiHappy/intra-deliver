<?php

namespace Tests\Feature\Ci;

use Tests\Support\FakesAptTools;
use Tests\TestCase;

/**
 * scripts/ci/apt-archives の件数の行と Actions の注意の出し方(索引に記録の無い .deb は件数だけ、SHA256 違い・形の外れは注意、
 * 集めたものが無い回は別の注意)と、道具の失敗・写した先の比べを、偽の道具で確かめる
 * (docs の 設計/コード構成/検査/一括検査の段.md 6.5)。基本は {@see AptArchivesScriptTest}・{@see AptArchivesCollectScriptTest}。
 * 道具は {@see FakesAptTools}。
 */
final class AptArchivesAnnotationScriptTest extends TestCase
{
    use FakesAptTools;

    public function test_verify_with_nothing_removed_is_no_annotation(): void
    {
        $sha = $this->deb($this->archive, 'bash', '5.2', 'amd64', 'bash body');
        $this->index('bash', 'amd64', '5.2', $sha);

        $result = $this->apt(['verify', $this->archive], githubActions: true);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertStringContainsString('残した 1・外した 0(うち記録なし 0)', $result->stderr);
        $this->assertStringNotContainsString('::warning', $result->stderr);
    }

    public function test_verify_counts_the_three_ways_of_no_record_and_raises_no_annotation(): void
    {
        // 名前が索引に無い(100 で E: No packages found の 1 行だけ)・版が索引に無い(0 で空)・status の記録(SHA256 が無い)
        $this->deb($this->archive, 'gone', '1.0', 'amd64', 'gone body');
        $this->deb($this->archive, 'bumped', '1.0', 'amd64', 'bumped body');
        $this->indexRaw('bumped:amd64=1.0', '');
        $this->deb($this->archive, 'status', '1.0', 'amd64', 'status body');
        $this->indexRaw('status:amd64=1.0', "Package: status\nStatus: install ok installed\n");

        $result = $this->apt(['verify', $this->archive], githubActions: true);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertStringContainsString('残した 0・外した 3(うち記録なし 3)', $result->stderr);
        $this->assertStringNotContainsString('::warning', $result->stderr);
    }

    public function test_verify_raises_the_annotation_for_a_sha256_mismatch(): void
    {
        $this->deb($this->archive, 'tampered', '1.0', 'amd64', 'tampered body');
        $this->index('tampered', 'amd64', '1.0', hash('sha256', 'the signed build'));

        $outside = $this->apt(['verify', $this->archive]);
        $this->deb($this->archive, 'tampered', '1.0', 'amd64', 'tampered body');
        $inside = $this->apt(['verify', $this->archive], githubActions: true);

        $this->assertStringContainsString('外した 1(うち記録なし 0)', $outside->stderr);
        $this->assertStringNotContainsString('::warning', $outside->stderr);
        $this->assertStringContainsString($this->unexpectedWarning(1, 0, '残した 0 個'), "\n".$inside->stderr);
    }

    public function test_verify_annotates_a_bad_name_but_not_a_missing_record(): void
    {
        $sha = $this->deb($this->archive, 'bash', '5.2', 'amd64', 'bash body');
        $this->index('bash', 'amd64', '5.2', $sha);
        $this->deb($this->archive, 'gone', '1.0', 'amd64', 'gone body');
        file_put_contents($this->archive.'/Upper_1.0_amd64.deb', 'x');

        $result = $this->apt(['verify', $this->archive], githubActions: true);

        $this->assertStringContainsString('残した 1・外した 2(うち記録なし 1)', $result->stderr);
        $this->assertStringContainsString($this->unexpectedWarning(0, 1, '残した 1 個'), "\n".$result->stderr);
    }

    public function test_collect_with_nothing_collected_raises_only_the_nothing_to_keep_annotation(): void
    {
        $this->deb($this->aptDirectory, 'bumped', '1.0', 'amd64', 'bumped body');
        $this->installed([['bumped:amd64', '1.0', 'ii ']]);

        $outside = $this->apt(['collect', $this->aptDirectory, $this->archive]);
        $inside = $this->apt(['collect', $this->aptDirectory, $this->archive], githubActions: true);

        $this->assertStringContainsString('集めた 0・外した 1(うち記録なし 1)・入っていない版 0', $outside->stderr);
        $this->assertStringNotContainsString('::warning', $outside->stderr);
        $this->assertStringContainsString("\n::warning title=apt の控え::控えるものが無い", "\n".$inside->stderr);
        $this->assertStringNotContainsString('索引の SHA256 と違う', $inside->stderr);
        $this->assertSame('', $inside->stdout);
    }

    public function test_collect_with_nothing_collected_and_a_mismatch_raises_both_annotations(): void
    {
        $this->deb($this->aptDirectory, 'tampered', '1.0', 'amd64', 'tampered body');
        $this->index('tampered', 'amd64', '1.0', hash('sha256', 'the signed build'));
        $this->installed([['tampered:amd64', '1.0', 'ii ']]);

        $result = $this->apt(['collect', $this->aptDirectory, $this->archive], githubActions: true);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertSame('', $result->stdout);
        $this->assertStringContainsString($this->unexpectedWarning(1, 0, '集めた 0 個'), "\n".$result->stderr);
        $this->assertStringContainsString("\n::warning title=apt の控え::控えるものが無い", "\n".$result->stderr);
    }

    public function test_a_copy_changed_after_the_check_is_a_mismatch_without_asking_the_index_again(): void
    {
        $bash = $this->deb($this->aptDirectory, 'bash', '5.2', 'amd64', 'bash body');
        $fonts = $this->deb($this->aptDirectory, 'fonts', '1.0', 'all', 'fonts body');
        $this->index('bash', 'amd64', '5.2', $bash);
        $this->index('fonts', 'all', '1.0', $fonts);
        $this->installed([['bash:amd64', '5.2', 'ii '], ['fonts:all', '1.0', 'ii ']]);

        $clean = $this->apt(['collect', $this->aptDirectory, $this->archive]);

        $shows = array_values(array_filter($this->calls(), fn (string $call): bool => str_contains($call, ' show ')));
        $this->assertSame(['apt-cache show bash:amd64=5.2', 'apt-cache show fonts:all=1.0'], $shows);
        $this->assertStringContainsString('集めた 2・外した 0', $clean->stderr);

        $this->corruptCopies();
        $result = $this->apt(['collect', $this->aptDirectory, $this->archive], githubActions: true);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertSame('', $result->stdout);
        $this->assertSame([], $this->files($this->archive));
        $this->assertStringContainsString('写した後に SHA256 が変わった', $result->stderr);
        $this->assertStringContainsString('集めた 0・外した 2(うち記録なし 0)', $result->stderr);
        $this->assertStringContainsString($this->unexpectedWarning(2, 0, '集めた 0 個'), "\n".$result->stderr);
    }

    public function test_apt_cache_show_failing_otherwise_is_a_tool_failure_without_counts_or_annotations(): void
    {
        $sha = $this->deb($this->archive, 'bash', '5.2', 'amd64', 'bash body');
        $this->index('bash', 'amd64', '5.2', $sha);
        $this->failShow('bash:amd64=5.2', 100, 'E: The package cache file is corrupted');
        $this->installed([['bash:amd64', '5.2', 'ii ']]);
        copy($this->archive.'/bash_5.2_amd64.deb', $this->aptDirectory.'/bash_5.2_amd64.deb');

        $verify = $this->apt(['verify', $this->archive], githubActions: true);
        $collect = $this->apt(['collect', $this->aptDirectory, $this->root.'/new'], githubActions: true);

        foreach ([$verify, $collect] as $result) {
            $this->assertSame(1, $result->exitCode, $result->describe());
            $reason = 'apt-cache show bash:amd64=5.2 が失敗した(終了コード 100): E: The package cache file is corrupted';
            $this->assertStringContainsString($reason, $result->stderr);
            $this->assertStringNotContainsString('apt の控え:', $result->stderr);
            $this->assertStringNotContainsString('::warning', $result->stderr);
            $this->assertSame('', $result->stdout);
        }
    }

    public function test_apt_cache_show_exiting_with_one_is_a_tool_failure(): void
    {
        $this->deb($this->archive, 'bash', '5.2', 'amd64', 'bash body');
        $this->failShow('bash:amd64=5.2', 1, 'E: something else');

        $result = $this->apt(['verify', $this->archive]);

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertStringContainsString('(終了コード 1): E: something else', $result->stderr);
        $this->assertSame(['bash_5.2_amd64.deb'], $this->files($this->archive));
    }

    public function test_a_failing_sha256sum_is_a_tool_failure_and_not_a_mismatch(): void
    {
        $this->deb($this->archive, 'bash', '5.2', 'amd64', 'bash body');
        $this->deb($this->aptDirectory, 'bash', '5.2', 'amd64', 'bash body');
        $this->installed([['bash:amd64', '5.2', 'ii ']]);
        $this->failSha256sumFor('bash_5.2_amd64.deb');

        $verify = $this->apt(['verify', $this->archive], githubActions: true);
        $collect = $this->apt(['collect', $this->aptDirectory, $this->root.'/new'], githubActions: true);

        foreach ([$verify, $collect] as $result) {
            $this->assertSame(1, $result->exitCode, $result->describe());
            $this->assertStringContainsString('sha256sum が失敗した: bash_5.2_amd64.deb', $result->stderr);
            $this->assertStringNotContainsString('外す:', $result->stderr);
            $this->assertStringNotContainsString('::warning', $result->stderr);
        }
    }
}
