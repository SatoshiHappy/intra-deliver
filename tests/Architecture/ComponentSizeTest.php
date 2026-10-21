<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Architecture\Engine\Baseline;
use Tests\Architecture\Engine\OxlintSizes;
use Tests\Architecture\Engine\ProcessResult;
use Tests\Architecture\Engine\Values;
use Tests\Architecture\Engine\ViteConfig;
use Tests\Architecture\Policy\Baselines;
use UnexpectedValueException;

/**
 * 画面側の部品の大きさ(docs の 設計/コード構成/検査/README.md 6.2)の照合と自己証明。数えるのは {@see OxlintSizes}。
 * 対象は vp lint と同じ範囲にするため、vite.config.ts の lint の ignorePatterns を読む。
 */
final class ComponentSizeTest extends BaselineTestCase
{
    public function test_the_tree_matches_the_baseline(): void
    {
        $root = dirname(__DIR__, 2);
        $actual = Baseline::of(self::overLimit($root));

        $this->assertMatchesBaseline($root, Baselines::COMPONENT_SIZE_CHECK, Baselines::COMPONENT_SIZE_FILE, $actual);
    }

    public function test_the_baseline_is_not_raised_from_main(): void
    {
        $this->assertNotRaisedFromMain(dirname(__DIR__, 2), Baselines::COMPONENT_SIZE_FILE);
    }

    public function test_counts_files_named_anonymous_async_and_repeated_functions_over_the_limit(): void
    {
        $this->assertSame([
            'anonymous.ts (無名)' => [52],
            'async-anonymous.ts (無名)' => [52],
            'async.ts loadAll' => [51],
            'duplicate.ts run' => [55, 51],
            'long-file.ts (ファイル)' => [301],
            'named.ts named' => [51],
        ], self::overLimit(__DIR__.'/fixtures/ComponentSize/violating'));
    }

    public function test_passes_a_file_and_a_function_at_the_limit_and_ignored_files(): void
    {
        $this->assertSame([], self::overLimit(__DIR__.'/fixtures/ComponentSize/passing'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unreadableMessages(): array
    {
        return [
            '想定の形でない' => ['The function `run` is too long.'],
            '上限が Policy の値と違う' => ['The function `run` has too many lines (61). Maximum allowed is 60.'],
        ];
    }

    #[DataProvider('unreadableMessages')]
    public function test_fails_on_a_message_it_cannot_read(string $message): void
    {
        $diagnostic = ['code' => 'eslint(max-lines-per-function)', 'filename' => 'a.ts', 'message' => $message];
        $result = new ProcessResult(['oxlint'], 1, (string) json_encode(['diagnostics' => [$diagnostic]]), '');

        $this->expectException(UnexpectedValueException::class);

        self::sizes()->parse($result);
    }

    public function test_fails_when_the_version_differs_from_the_pin(): void
    {
        $this->expectException(UnexpectedValueException::class);

        OxlintSizes::assertPinned('1.80.0', '=1.79.0', Baselines::OXLINT_WRAPPER);
    }

    public function test_fails_when_oxlint_cannot_be_resolved(): void
    {
        $this->expectException(UnexpectedValueException::class);

        OxlintSizes::resolve(
            dirname(__DIR__, 2),
            'no-such-wrapper-package',
            Baselines::MAX_FILE_LINES,
            Baselines::MAX_FUNCTION_LINES,
        );
    }

    private static function sizes(): OxlintSizes
    {
        return OxlintSizes::resolve(
            dirname(__DIR__, 2),
            Baselines::OXLINT_WRAPPER,
            Baselines::MAX_FILE_LINES,
            Baselines::MAX_FUNCTION_LINES,
        );
    }

    /**
     * @return array<string, list<int>>
     */
    private static function overLimit(string $root): array
    {
        $lint = ViteConfig::lintSettings(dirname(__DIR__, 2), $root.'/'.Baselines::VITE_CONFIG);

        $ignorePatterns = Values::optionalStrings($lint, 'ignorePatterns', Baselines::VITE_CONFIG.' の lint');

        return self::sizes()->overLimit($root, $ignorePatterns);
    }
}
