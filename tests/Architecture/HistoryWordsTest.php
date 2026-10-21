<?php

namespace Tests\Architecture;

use Tests\Architecture\Engine\Baseline;
use Tests\Architecture\Engine\HistoryWords as HistoryWordsCheck;
use Tests\Architecture\Engine\TrackedFiles;
use Tests\Architecture\Policy\Baselines;
use Tests\Architecture\Policy\HistoryWords;

/**
 * 経緯の語(docs の 設計/コード構成/検査/README.md 6.2)の照合と自己証明。語は {@see HistoryWords}、判定は {@see HistoryWordsCheck}、
 * 対象は {@see TrackedFiles::targets()}。
 * 語の例は fixture にだけ書く(このファイルに書くと、実物の木の照合がこのファイルを数える)。
 */
final class HistoryWordsTest extends BaselineTestCase
{
    public function test_the_tree_matches_the_baseline(): void
    {
        $root = dirname(__DIR__, 2);

        $this->assertMatchesBaseline(
            $root,
            Baselines::HISTORY_WORDS_CHECK,
            Baselines::HISTORY_WORDS_FILE,
            Baseline::of(self::occurrences($root)),
        );
    }

    public function test_the_baseline_is_not_raised_from_main(): void
    {
        $this->assertNotRaisedFromMain(dirname(__DIR__, 2), Baselines::HISTORY_WORDS_FILE);
    }

    public function test_counts_each_kind_of_word(): void
    {
        $this->assertSame([
            'bare-sub.txt' => 1,
            'bare.txt' => 1,
            'cut.txt' => 1,
            'policy-name.txt' => 1,
            'stage-joined.txt' => 1,
            'stage-spaced.txt' => 1,
            'stage-sub.txt' => 1,
        ], self::occurrences(__DIR__.'/fixtures/HistoryWords/violating'));
    }

    public function test_passes_other_words_identifiers_references_and_excluded_paths(): void
    {
        $this->assertSame([], self::occurrences(__DIR__.'/fixtures/HistoryWords/passing'));
    }

    /**
     * 根 $root の対象のファイルごとの語の数(ファイルの名前順)。
     *
     * @return array<string, int>
     */
    private static function occurrences(string $root): array
    {
        $files = TrackedFiles::targets($root, self::ledger($root), Baselines::HISTORY_WORDS_CHECK);
        $counts = HistoryWordsCheck::count($root, $files, HistoryWords::PATTERN);
        ksort($counts, SORT_STRING);

        return $counts;
    }
}
