<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Architecture\Engine\Findings;
use Tests\Architecture\Engine\Process;
use Tests\Architecture\Engine\ProcessResult;

/**
 * 画面側の import の向き(docs の 設計/コード構成/README.md 5 節の「止める向き」。dependency-cruiser の設定 .dependency-cruiser.cjs。K15・D04)の自己証明。
 * 止める向きの 1 項目ごと(と循環)に、違反の fixture が赤になり、許された向きだけの fixture が通る。
 *
 * fixture の各ディレクトリ(tests/Architecture/fixtures/Frontend/{violating,passing})を web/ に見立てて(resources/js と tsconfig.json を持つ)、
 * 通常と同じ設定のまま dependency-cruiser を走らせる。
 */
final class FrontendImportsSelfProofTest extends SelfProofTestCase
{
    /** @var array<string, ProcessResult> fixture の種類ごとの結果(同じ入力に depcruise を何度も走らせない) */
    private static array $results = [];

    protected function findings(ProcessResult $result): array
    {
        return Findings::fromLines($result->stdout, '/^\s*(?:error|warn)\s+(?<rule>[A-Za-z0-9-]+): (?<file>\S+)/');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function violations(): array
    {
        return [
            'ui/ → 共通の直下の components' => ['ui-only-to-ui-and-shared-lib-hooks', 'resources/js/components/ui/button.ts'],
            '共通の直下 → <M>/' => ['shared-not-to-module', 'resources/js/components/Shared.ts'],
            '<M>/ → 別の <M>/' => ['module-not-to-other-module', 'resources/js/components/Records/Form.ts'],
            'pages/<M>/ → 別の <M>/' => ['module-not-to-other-module', 'resources/js/pages/Records/Index.ts'],
            '共通 → layouts' => ['shared-ui-module-not-to-layouts-or-pages', 'resources/js/components/Panel.ts'],
            'layouts → pages' => ['layouts-not-to-pages', 'resources/js/layouts/AppLayout.ts'],
            'lib → components' => ['lib-hooks-not-to-components', 'resources/js/lib/helper.ts'],
            '本番のコード → テスト専用の手助け' => ['testing-helpers-only-from-tests', 'resources/js/lib/production.ts'],
            '循環' => ['no-circular', 'resources/js/lib/a.ts'],
        ];
    }

    #[DataProvider('violations')]
    public function test_detects_an_import_in_a_forbidden_direction(string $rule, string $file): void
    {
        $this->assertDetects($rule, $file, null, self::cruise('violating'));
    }

    public function test_passes_imports_in_allowed_directions_only(): void
    {
        $this->assertClean(self::cruise('passing'));
    }

    private static function cruise(string $kind): ProcessResult
    {
        $root = dirname(__DIR__, 2);

        return self::$results[$kind] ??= Process::run(
            [$root.'/node_modules/.bin/depcruise', 'resources/js', '--config', $root.'/.dependency-cruiser.cjs', '--output-type', 'err'],
            $root.'/tests/Architecture/fixtures/Frontend/'.$kind,
        );
    }
}
