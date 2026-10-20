<?php

namespace Tests\Architecture;

use Tests\Architecture\Engine\Baseline;
use Tests\Architecture\Engine\LineLengths;
use Tests\Architecture\Engine\SourceTokens;
use Tests\Architecture\Engine\TrackedFiles;
use Tests\Architecture\Engine\Values;
use Tests\Architecture\Engine\ViteConfig;
use Tests\Architecture\Policy\Baselines;

/**
 * 行の長さ(docs の 設計/コード構成/検査.md 6 節)の照合と自己証明。判定は {@see LineLengths}。
 * 画面側の対象は vp lint と同じ範囲にするため、vite.config.ts の lint の ignorePatterns を読む。
 */
final class LineLengthTest extends BaselineTestCase
{
    public function test_the_tree_matches_the_baseline(): void
    {
        $root = dirname(__DIR__, 2);

        $actual = Baseline::of(self::overLimit($root));

        $this->assertMatchesBaseline($root, Baselines::LINE_LENGTH_CHECK, Baselines::LINE_LENGTH_FILE, $actual);
    }

    public function test_the_baseline_is_not_raised_from_main(): void
    {
        $this->assertNotRaisedFromMain(dirname(__DIR__, 2), Baselines::LINE_LENGTH_FILE);
    }

    public function test_counts_lines_over_the_limit_including_full_width_characters(): void
    {
        $expected = ['app/Long.php' => 2, 'resources/js/long.tsx' => 1];

        $this->assertSame($expected, self::overLimit(self::fixture('violating')));
    }

    public function test_passes_lines_at_the_limit_and_files_out_of_scope(): void
    {
        $this->assertSame([], self::overLimit(self::fixture('passing')));
    }

    private static function fixture(string $kind): string
    {
        return __DIR__.'/fixtures/LineLength/'.$kind;
    }

    /**
     * 根 $root の対象のファイルごとの、上限を超える行の数。
     *
     * @return array<string, int>
     */
    private static function overLimit(string $root): array
    {
        $php = SourceTokens::globFiles($root, ['**/*.php'], self::ledger($root)->paths([Baselines::LINE_LENGTH_CHECK]));
        $lint = ViteConfig::lintSettings(dirname(__DIR__, 2), $root.'/'.Baselines::VITE_CONFIG);
        $scripts = TrackedFiles::without(
            TrackedFiles::withExtensions(TrackedFiles::notIgnored($root), Baselines::SCRIPT_EXTENSIONS),
            Values::optionalStrings($lint, 'ignorePatterns', Baselines::VITE_CONFIG.' の lint'),
        );

        return LineLengths::overLimit($root, [...$php, ...$scripts], Baselines::MAX_LINE_LENGTH);
    }
}
