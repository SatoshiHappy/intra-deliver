<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Architecture\Engine\PhpStanParameters;
use Tests\Architecture\Engine\Values;
use Tests\Architecture\Policy\SourceDirectories;
use Tests\Architecture\Policy\Verification;

/**
 * クラスの置き場の一覧({@see SourceDirectories}。手元だけのリポジトリの移した版を含む)を、同じ一覧を書く設定が落とさずに持つことの照合
 * (docs の ADR 0039 の決定 8 = 移した版は Ai の中のコードとして同じ 1 回の検査で見る。黙って規則が外れる道を作らない)。
 * composer.json の psr-4 と、phpstan が解決した codeLayout・sideEffects・singleClassFile を見る。
 */
final class SourceDirectoriesTest extends TestCase
{
    public function test_composer_maps_the_root_namespaces_to_every_class_directory(): void
    {
        $composer = Values::jsonFile(self::root().'/'.Verification::COMPOSER_JSON);
        $autoload = self::psr4($composer, 'autoload');
        $autoloadDev = self::psr4($composer, 'autoload-dev');

        $app = SourceDirectories::APP_NAMESPACE.'\\';
        $tests = SourceDirectories::TESTS_NAMESPACE.'\\';

        $this->assertSame(self::withSlash(SourceDirectories::APP), $autoload[$app] ?? null);
        $this->assertSame(self::withSlash(SourceDirectories::TESTS), $autoloadDev[$tests] ?? null);
    }

    public function test_phpstan_treats_every_class_directory_alike(): void
    {
        $parameters = PhpStanParameters::dump(self::root(), self::root().'/'.Verification::PHPSTAN_CONFIG);
        $codeLayout = Values::table($parameters['codeLayout'] ?? null, 'codeLayout');
        $sideEffects = Values::table($parameters['sideEffects'] ?? null, 'sideEffects');
        $classDirectories = Values::strings($codeLayout['classDirectories'] ?? null, 'codeLayout.classDirectories');
        $exempt = Values::strings($sideEffects['exemptDirectories'] ?? null, 'sideEffects.exemptDirectories');

        $all = [...SourceDirectories::APP, ...SourceDirectories::TESTS];

        $this->assertSame([], array_values(array_diff($all, $classDirectories)));
        $this->assertSame([], array_values(array_diff(SourceDirectories::TESTS, $exempt)));
        $this->assertSame([], array_values(array_intersect(SourceDirectories::APP, $exempt)));
        $this->assertSame(
            array_map(
                fn (string $directory): array => [
                    'directory' => $directory,
                    'namespace' => SourceDirectories::APP_NAMESPACE,
                ],
                SourceDirectories::APP,
            ),
            $parameters['singleClassFile'] ?? null,
        );
    }

    /**
     * composer.json の節 $section の psr-4(名前空間 → ディレクトリ)。
     *
     * @param  array<mixed>  $composer
     * @return array<mixed>
     */
    private static function psr4(array $composer, string $section): array
    {
        return Values::table(Values::table($composer[$section] ?? null, $section)['psr-4'] ?? null, "{$section}.psr-4");
    }

    /**
     * @param  list<string>  $directories
     * @return list<string>
     */
    private static function withSlash(array $directories): array
    {
        return array_map(fn (string $directory): string => $directory.'/', $directories);
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
