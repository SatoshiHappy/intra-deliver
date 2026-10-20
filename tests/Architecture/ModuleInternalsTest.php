<?php

namespace Tests\Architecture;

use Tests\Architecture\Engine\Baseline;
use Tests\Architecture\Engine\InternalDependencies;
use Tests\Architecture\Engine\SourceTokens;
use Tests\Architecture\Policy\Baselines;
use Tests\Architecture\Policy\ModuleInternals;

/**
 * ほかのモジュールの内部への依存の数(docs の 設計/コード構成/検査.md 6 節)の照合と自己証明。対象と判定は {@see ModuleInternals}・{@see InternalDependencies}。
 */
final class ModuleInternalsTest extends BaselineTestCase
{
    public function test_the_tree_matches_the_baseline(): void
    {
        $root = dirname(__DIR__, 2);

        $this->assertMatchesBaseline(
            $root,
            Baselines::MODULE_INTERNALS_CHECK,
            Baselines::MODULE_INTERNALS_FILE,
            Baseline::of(InternalDependencies::counts(self::pairs($root))),
        );
    }

    public function test_the_baseline_is_not_raised_from_main(): void
    {
        $this->assertNotRaisedFromMain(dirname(__DIR__, 2), Baselines::MODULE_INTERNALS_FILE);
    }

    public function test_counts_each_way_of_naming_an_internal_class_once_per_file_and_class(): void
    {
        $this->assertSame([
            'app/Actions/Workflow/UsesAlias.php → App\Actions\Master\DoThing',
            'app/Actions/Workflow/UsesImport.php → App\Actions\Master\DoThing',
            'app/Actions/Workflow/UsesTwice.php → App\Actions\Master\DoThing',
            'app/Http/Controllers/Web/Workflow/UsesEntrance.php → App\Http\Requests\Records\ReadsThing',
            'app/Http/Controllers/Web/Workflow/UsesString.php → App\Queries\Master\FindThing',
            'app/Queries/Workflow/UsesDocOnly.php → App\Queries\Master\FindThing',
            'app/Queries/Workflow/UsesQualified.php → App\Queries\Master\FindThing',
        ], array_map(
            fn (array $pair): string => $pair['file'].' → '.$pair['class'],
            self::pairs(__DIR__.'/fixtures/ModuleInternals/violating'),
        ));
        $this->assertSame(
            ['Workflow → Master' => 6, 'Workflow → Records' => 1],
            InternalDependencies::counts(self::pairs(__DIR__.'/fixtures/ModuleInternals/violating')),
        );
    }

    public function test_passes_api_same_module_non_transitional_models_enums_missing_classes_and_wiring(): void
    {
        $this->assertSame([], self::pairs(__DIR__.'/fixtures/ModuleInternals/passing'));
    }

    /**
     * @return list<array{file: string, from: string, to: string, class: string}>
     */
    private static function pairs(string $root): array
    {
        $files = SourceTokens::globFiles(
            $root,
            [ModuleInternals::APP_DIRECTORY.'/**/*.php'],
            self::ledger($root)->paths([Baselines::MODULE_INTERNALS_CHECK]),
        );

        return ModuleInternals::dependencies()->pairs($root, $files);
    }
}
