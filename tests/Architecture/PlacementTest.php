<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Architecture\Engine\Finding;
use Tests\Architecture\Engine\Placement;
use Tests\Architecture\Engine\Values;
use Tests\Architecture\Policy\CodeLayout;
use Tests\Architecture\Policy\Modules;

/**
 * 置き場の規則(docs の 設計/コード構成/README.md 1 節・4 節・5 節)の照合と自己証明。
 *
 * - app/ の直下は台帳① のディレクトリだけ(ファイルは置かない)
 * - app/Http と app/Http/Controllers の直下は決めた置き場だけ
 * - <M> を持つ 8 つの置き場と resources/js/pages の直下は宣言したモジュールだけ(ファイルは置かない。app/Http/Middleware の直下だけはファイルを置ける)
 * - resources/js の components・lib・hooks の直下のディレクトリは宣言したモジュールだけ(components は加えて ui。直下のファイル = 共通の部品は置ける)
 * - resources/views は app.blade.php だけ
 *
 * 違反 fixture = tests/Architecture/fixtures/Placement/violating(規則ごとに 1 つ以上の違反)、正常 fixture = 同 passing。
 * fixture の照合では、台帳① は実物、モジュールの宣言は fixture 用の Alpha・Beta を渡す(実物の宣言が変わっても fixture が変わらないように)。
 */
final class PlacementTest extends TestCase
{
    private const FIXTURE_MODULES = ['Alpha', 'Beta'];

    public function test_the_application_follows_the_placement_rules(): void
    {
        $findings = Placement::findings(self::appRoot(), CodeLayout::placements(self::baseDirectories(), Modules::names()));

        $this->assertSame([], self::describe($findings), '置き場の規則に合わないディレクトリ・ファイルがある');
    }

    public function test_detects_every_kind_of_misplacement(): void
    {
        $findings = Placement::findings(self::fixture('violating'), CodeLayout::placements(self::baseDirectories(), self::FIXTURE_MODULES));

        $this->assertEqualsCanonicalizing([
            'placement.directory app/Listeners',
            'placement.directory app/Jobs/Gamma',
            'placement.directory app/Console/Commands/Gamma',
            'placement.file app/Stray.php',
            'placement.directory app/Http/Resources',
            'placement.file app/Http/Kernel.php',
            'placement.directory app/Http/Controllers/Admin',
            'placement.file app/Http/Controllers/HomeController.php',
            'placement.directory app/Http/Controllers/Web/Gamma',
            'placement.directory app/Http/Middleware/Gamma',
            'placement.directory app/Actions/Gamma',
            'placement.file app/Actions/Stray.php',
            'placement.directory app/Enums/Gamma',
            'placement.directory resources/js/pages/Gamma',
            'placement.directory resources/js/components/Gamma',
            'placement.directory resources/js/lib/Gamma',
            'placement.directory resources/js/lib/ui',
            'placement.directory resources/js/hooks/Gamma',
            'placement.file resources/js/pages/Home.tsx',
            'placement.directory resources/views/layouts',
            'placement.file resources/views/welcome.blade.php',
        ], self::describe($findings));
    }

    public function test_passes_declared_modules_and_fixed_places(): void
    {
        $findings = Placement::findings(self::fixture('passing'), CodeLayout::placements(self::baseDirectories(), self::FIXTURE_MODULES));

        $this->assertSame([], self::describe($findings));
    }

    /**
     * @param  list<Finding>  $findings
     * @return list<string>
     */
    private static function describe(array $findings): array
    {
        return array_map(fn (Finding $finding): string => $finding->ruleId.' '.$finding->file, $findings);
    }

    /**
     * @return list<string>
     */
    private static function baseDirectories(): array
    {
        $allow = Values::table(require self::appRoot().'/config/guardrails_allow.php', 'config/guardrails_allow.php');

        return Values::strings($allow['base_directories'] ?? null, '台帳① base_directories');
    }

    private static function fixture(string $kind): string
    {
        return __DIR__.'/fixtures/Placement/'.$kind;
    }

    private static function appRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
