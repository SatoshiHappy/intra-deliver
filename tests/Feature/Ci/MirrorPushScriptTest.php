<?php

namespace Tests\Feature\Ci;

use Tests\Support\RunsMirrorScripts;
use Tests\TestCase;

/**
 * 写しへの push scripts/mirror/push(履歴なしで今の木を写しの同じ名前の枝へ送る)を、ローカルの bare リポジトリを写しにして確かめる。
 * 本物の写し・ネットワークは使わない。道具は {@see RunsMirrorScripts}。
 */
final class MirrorPushScriptTest extends TestCase
{
    use RunsMirrorScripts;

    public function test_it_creates_a_parentless_commit_with_the_same_tree_in_an_empty_mirror(): void
    {
        $source = $this->sourceHead();

        $result = $this->push();

        $this->assertSame(0, $result->exitCode, $result->describe());
        $mirrorHead = $this->mirrorHead('main');
        $this->assertNotNull($mirrorHead);
        $this->assertSame('', $this->mirrorCommitField($mirrorHead, '%P'));
        $this->assertSame($this->sourceTree($source), $this->mirrorCommitField($mirrorHead, '%T'));
        $this->assertSame('SatoshiHappy', $this->mirrorCommitField($mirrorHead, '%an'));
        $this->assertSame('SatoshiHappy', $this->mirrorCommitField($mirrorHead, '%cn'));
        $this->assertSame(self::MIRROR_EMAIL, $this->mirrorCommitField($mirrorHead, '%ae'));
        $this->assertSame(self::MIRROR_EMAIL, $this->mirrorCommitField($mirrorHead, '%ce'));
        $this->assertSame('写し: main '.$source, $this->mirrorCommitField($mirrorHead, '%B'));
        $this->assertStringContainsString($mirrorHead, $result->stdout);
        $this->assertStringContainsString(
            'https://github.com/SatoshiHappy/intra-deliver/actions?query=branch%3Amain',
            $result->stdout,
        );
    }

    public function test_it_chains_a_new_commit_on_the_head_of_the_same_branch(): void
    {
        $this->pushSucceeds();
        $previous = $this->mirrorHead('main');
        $source = $this->commitFile('second.txt', '非公開の 2 つ目のコミット');

        $result = $this->push();

        $this->assertSame(0, $result->exitCode, $result->describe());
        $mirrorHead = $this->mirrorHead('main');
        $this->assertNotNull($mirrorHead);
        $this->assertNotSame($previous, $mirrorHead);
        $this->assertSame($previous, $this->mirrorCommitField($mirrorHead, '%P'));
        $this->assertSame($this->sourceTree($source), $this->mirrorCommitField($mirrorHead, '%T'));
        $this->assertSame('写し: main '.$source, $this->mirrorCommitField($mirrorHead, '%B'));
    }

    public function test_a_new_branch_starts_from_the_head_of_main_of_the_mirror(): void
    {
        $this->pushSucceeds();
        $mirrorMain = $this->mirrorHead('main');
        $this->git('switch', '-q', '-c', 'feature-x');
        $source = $this->commitFile('feature.txt', '非公開の枝のコミット');

        $result = $this->push();

        $this->assertSame(0, $result->exitCode, $result->describe());
        $feature = $this->mirrorHead('feature-x');
        $this->assertNotNull($feature);
        $this->assertSame($mirrorMain, $this->mirrorCommitField($feature, '%P'));
        $this->assertSame($this->sourceTree($source), $this->mirrorCommitField($feature, '%T'));
        $this->assertSame('写し: feature-x '.$source, $this->mirrorCommitField($feature, '%B'));
        $this->assertSame($mirrorMain, $this->mirrorHead('main'));
        $this->assertStringContainsString('branch%3Afeature-x', $result->stdout);
    }

    public function test_a_branch_pushed_again_after_main_moved_takes_the_new_main_as_a_second_parent(): void
    {
        $this->pushSucceeds();
        $this->git('switch', '-q', '-c', 'feature-x');
        $this->commitFile('feature.txt', '非公開の枝のコミット');
        $this->pushSucceeds();
        $previousFeature = $this->mirrorHead('feature-x');
        $this->git('switch', '-q', 'main');
        $this->commitFile('main.txt', '非公開の main の次のコミット');
        $this->pushSucceeds();
        $newMain = $this->mirrorHead('main');
        $this->git('switch', '-q', 'feature-x');
        $this->git('merge', '-q', '--no-edit', 'main');
        $source = $this->sourceHead();

        $result = $this->push();

        $this->assertSame(0, $result->exitCode, $result->describe());
        $feature = $this->mirrorHead('feature-x');
        $this->assertNotNull($feature);
        $this->assertSame($previousFeature.' '.$newMain, $this->mirrorCommitField($feature, '%P'));
        $this->assertSame($this->sourceTree($source), $this->mirrorCommitField($feature, '%T'));
        $this->assertSame($newMain, trim($this->mirrorGit('merge-base', $feature, 'main')->stdout));
    }

    public function test_the_second_run_in_a_row_reports_no_change_and_does_not_move_the_mirror(): void
    {
        $this->pushSucceeds();
        $refs = $this->mirrorRefs();

        $result = $this->push();

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertStringContainsString('変わっていない', $result->stdout);
        $this->assertSame($refs, $this->mirrorRefs());
    }

    public function test_the_history_of_the_source_never_reaches_the_mirror(): void
    {
        $sources = [$this->sourceHead()];
        $this->pushSucceeds();
        $sources[] = $this->commitFile('second.txt', '非公開の 2 つ目のコミット');
        $this->pushSucceeds();
        $this->git('switch', '-q', '-c', 'feature-x');
        $sources[] = $this->commitFile('feature.txt', '非公開の枝のコミット');
        $this->pushSucceeds();

        $mirrorCommits = array_filter(explode("\n", $this->mirrorGit('rev-list', '--all')->stdout));

        $this->assertCount(3, $mirrorCommits);
        $this->assertSame([], array_intersect($sources, $mirrorCommits));
        $log = $this->mirrorGit('log', '--all', '--format=%ae%n%ce')->stdout;
        $emails = array_unique(array_filter(explode("\n", $log)));
        $this->assertSame([self::MIRROR_EMAIL], array_values($emails));
        $this->assertStringNotContainsString('非公開', $this->mirrorGit('log', '--all', '--format=%B')->stdout);
    }

    public function test_it_stops_on_a_detached_head(): void
    {
        $this->pushSucceeds();
        $refs = $this->mirrorRefs();
        $this->git('switch', '-q', '--detach');

        $result = $this->push();

        $this->assertSame(2, $result->exitCode, $result->describe());
        $this->assertStringContainsString('detached', $result->stderr);
        $this->assertSame($refs, $this->mirrorRefs());
    }

    public function test_it_stops_on_a_tracked_change(): void
    {
        $this->pushSucceeds();
        $refs = $this->mirrorRefs();
        file_put_contents($this->work.'/first.txt', "書き換えた\n");

        $result = $this->push();

        $this->assertSame(2, $result->exitCode, $result->describe());
        $this->assertStringContainsString('未コミットの変更', $result->stderr);
        $this->assertSame($refs, $this->mirrorRefs());
    }

    public function test_it_stops_on_an_untracked_file(): void
    {
        $this->pushSucceeds();
        $refs = $this->mirrorRefs();
        file_put_contents($this->work.'/untracked.txt', "追跡しない\n");

        $result = $this->push();

        $this->assertSame(2, $result->exitCode, $result->describe());
        $this->assertStringContainsString('未コミットの変更', $result->stderr);
        $this->assertSame($refs, $this->mirrorRefs());
    }

    public function test_it_stops_when_an_argument_is_given(): void
    {
        $result = $this->push(['main']);

        $this->assertSame(2, $result->exitCode, $result->describe());
        $this->assertStringContainsString('使い方', $result->stderr);
        $this->assertNull($this->mirrorHead('main'));
    }

    public function test_it_fails_when_the_mirror_does_not_exist(): void
    {
        $result = $this->push(url: $this->root.'/no-such-mirror.git');

        $this->assertNotSame(0, $result->exitCode, $result->describe());
        $this->assertNotSame('', $result->stderr);
    }

    public function test_it_scans_the_tree_to_send_with_gitleaks_before_pushing(): void
    {
        $result = $this->push();

        $this->assertSame(0, $result->exitCode, $result->describe());
        $log = (string) file_get_contents($this->gitleaksLog);
        $this->assertMatchesRegularExpression('/^args: dir \. .*--config \S*\.gitleaks\.toml .*--redact/m', $log);
        $this->assertStringContainsString("file: ./first.txt\n", $log);
        $this->assertStringContainsString("file: ./scripts/mirror/push\n", $log);
        $this->assertNotNull($this->mirrorHead('main'));
        $this->assertSame([], $this->leftoverTemporaryEntries());
    }

    public function test_it_scans_tracked_files_marked_export_ignore_too(): void
    {
        file_put_contents($this->work.'/.gitattributes', "ignored.txt export-ignore\n");
        $this->git('add', '.gitattributes');
        $this->commitFile('ignored.txt', '配布から外す印の付いた追跡ファイル');

        $result = $this->push();

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertStringContainsString("file: ./ignored.txt\n", (string) file_get_contents($this->gitleaksLog));
    }

    public function test_it_stops_without_pushing_when_gitleaks_finds_a_leak(): void
    {
        $refs = $this->mirrorRefs();

        $result = $this->push(environment: ['MIRROR_GITLEAKS' => $this->fakeGitleaks(1)]);

        $this->assertSame(2, $result->exitCode, $result->describe());
        $this->assertStringContainsString('gitleaks', $result->stderr);
        $this->assertStringContainsString('秘密の値', $result->stderr);
        $this->assertNull($this->mirrorHead('main'));
        $this->assertSame($refs, $this->mirrorRefs());
        $this->assertSame([], $this->leftoverTemporaryEntries());
    }

    public function test_it_stops_without_contacting_the_mirror_when_gitleaks_cannot_run(): void
    {
        $result = $this->push(
            url: $this->root.'/no-such-mirror.git',
            environment: ['MIRROR_GITLEAKS' => $this->root.'/no-such-gitleaks'],
        );

        $this->assertSame(2, $result->exitCode, $result->describe());
        $this->assertStringContainsString('gitleaks', $result->stderr);
        $this->assertStringNotContainsString('写しの枝の一覧', $result->stderr);
        $this->assertSame([], $this->leftoverTemporaryEntries());
    }

    public function test_a_failed_fetch_of_the_mirror_stops_the_push_instead_of_using_the_stale_fetch_head(): void
    {
        $this->pushSucceeds();
        $this->commitFile('second.txt', '非公開の 2 つ目のコミット');
        $this->pushSucceeds();
        $this->git('switch', '-q', '-c', 'feature-x');
        $this->commitFile('feature.txt', '非公開の枝のコミット');
        $refs = $this->mirrorRefs();

        $result = $this->push(environment: ['PATH' => $this->gitThatFailsToFetch().':'.getenv('PATH')]);

        $this->assertNotSame(0, $result->exitCode, $result->describe());
        $this->assertStringContainsString('写しの枝の一覧を取れませんでした', $result->stderr);
        $this->assertStringNotContainsString('写しに main の枝がありません', $result->stderr);
        $this->assertSame($refs, $this->mirrorRefs());
    }

    public function test_it_stops_when_a_branch_other_than_main_is_sent_to_a_mirror_without_main(): void
    {
        $this->git('switch', '-q', '-c', 'feature-x');
        $this->commitFile('feature.txt', '非公開の枝のコミット');

        $result = $this->push();

        $this->assertSame(2, $result->exitCode, $result->describe());
        $this->assertStringContainsString('写しに main の枝がありません', $result->stderr);
        $this->assertSame('', $this->mirrorRefs());
    }

    public function test_a_new_branch_with_the_same_tree_as_main_tells_that_no_branch_is_created(): void
    {
        $this->pushSucceeds();
        $refs = $this->mirrorRefs();
        $this->git('switch', '-q', '-c', 'feature-x');

        $result = $this->push();

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertStringContainsString('写しの main と同じ木', $result->stdout);
        $this->assertStringContainsString('CI も走りません', $result->stdout);
        $this->assertNull($this->mirrorHead('feature-x'));
        $this->assertSame($refs, $this->mirrorRefs());
    }

    private function pushSucceeds(): void
    {
        $result = $this->push();

        $this->assertSame(0, $result->exitCode, $result->describe());
    }
}
