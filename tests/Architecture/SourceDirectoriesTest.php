<?php

namespace Tests\Architecture;

use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Architecture\Engine\LinkedTree;
use Tests\Architecture\Engine\PhpStanParameters;
use Tests\Architecture\Engine\TempDirectory;
use Tests\Architecture\Engine\Values;
use Tests\Architecture\Engine\XmlFile;
use Tests\Architecture\Policy\SourceDirectories;
use Tests\Architecture\Policy\Verification;
use UnexpectedValueException;

/**
 * クラスの置き場の一覧({@see SourceDirectories}。手元だけのリポジトリの移した版を含む)を、同じ一覧を書く設定が落とさずに持つことの照合
 * (docs の ADR 0039 の決定 8 = 移した版は Ai の中のコードとして同じ 1 回の検査で見る。黙って規則が外れる道を作らない)。
 * composer.json の psr-4 と、phpstan が解決した codeLayout・sideEffects・singleClassFile と、手元の phpunit の設定の suite を見る。
 * 手元の suite の照合は、手元のリポジトリの有る木と無い木で同じ 1 つの確かめにする(無い木ではどちらの集まりも空。テストを飛ばさない)。
 * その判定は一時の木(build/fixtures の下にテストのたびに作る写しと、木の外の実体の置き場を指すリンク)でも通す。
 */
final class SourceDirectoriesTest extends TestCase
{
    /** 一時の木の手元の phpunit の設定の形(%1$s = suite の子、%2$s = testsuites の後ろの要素) */
    private const PRIVATE_CONFIG = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <phpunit>
            <testsuites>
                <testsuite name="Private">%1$s</testsuite>
            </testsuites>
            %2$s
        </phpunit>
        XML;

    /** 一時の木の根(置き場を作った直後から持ち、作る途中で落ちても後始末が親の置き場ごと消す) */
    private ?string $tree = null;

    protected function tearDown(): void
    {
        if ($this->tree !== null) {
            LinkedTree::remove($this->tree, [SourceDirectories::PRIVATE_ROOT]);
            TempDirectory::remove(dirname($this->tree));
        }

        parent::tearDown();
    }

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

    /** 手元の phpunit の設定の suite が挙げるディレクトリの集まりが、在る手元のテストの置き場の集まりと等しい(手元のテストが黙って流れない道を作らない) */
    public function test_the_private_phpunit_config_lists_exactly_the_private_test_directories(): void
    {
        [$listed, $present] = self::privateTestDirectories(self::root());

        $this->assertSame($present, $listed);
    }

    /**
     * 手元の suite の照合の組(根の ai-private の有無・手元の tests の有無・手元の設定(null = 無い)・通るか)。根の ai-private が無ければ
     * その下の tests と設定も無い(判定は道だけを見る)。設定が読めない・許す形の外の組は {@see self::unreadableConfigs()}(ほかの条件によらず落ちる)。
     *
     * @return array<string, array{bool, bool, string|null, bool}>
     */
    public static function privateTrees(): array
    {
        $tests = self::config('<directory>tests</directory>');
        $other = self::config('<directory>other</directory>');
        $none = self::config('');
        $nested = self::config('<directory>tests/Feature</directory><directory>tests/Unit</directory>');
        $spelled = self::config('<directory>./tests</directory><directory>tests/</directory>');
        $both = self::config('<directory>tests</directory><directory>other</directory>');

        return [
            '手元が無い' => [false, false, null, true],
            'tests が在り、設定が tests だけを挙げる' => [true, true, $tests, true],
            'tests が在り、設定が書き方を変えて tests を重ねて挙げる' => [true, true, $spelled, true],
            'tests が在り、設定が無い' => [true, true, null, false],
            'tests が在り、設定が別のディレクトリを挙げる' => [true, true, $other, false],
            'tests が在り、設定が tests と別のディレクトリを挙げる' => [true, true, $both, false],
            'tests が在り、設定が下のディレクトリを挙げる' => [true, true, $nested, false],
            'tests が在り、設定がディレクトリを挙げない' => [true, true, $none, false],
            'tests が無く、設定が無い' => [true, false, null, true],
            'tests が無く、設定が tests だけを挙げる' => [true, false, $tests, false],
            'tests が無く、設定が別のディレクトリを挙げる' => [true, false, $other, false],
            'tests が無く、設定がディレクトリを挙げない' => [true, false, $none, true],
        ];
    }

    #[DataProvider('privateTrees')]
    public function test_the_private_suite_check_on_a_temporary_tree(
        bool $linked,
        bool $tests,
        ?string $config,
        bool $passes,
    ): void {
        [$listed, $present] = self::privateTestDirectories($this->temporaryTree($linked, $tests, $config));

        $this->assertSame($passes, $listed === $present);
    }

    /**
     * 読めない手元の設定(XML として読めない・許す形の外)と、落ちるときの理由の文の一部(手元の tests の有無によらないので、在る木で流す)。
     *
     * @return array<string, array{string, string}>
     */
    public static function unreadableConfigs(): array
    {
        return [
            'XML として読めない' => [self::config('<directory>tests</directory'), 'XML として読めない'],
            'directory に属性がある' => [self::config('<directory suffix="Test.php">tests</directory>'), '属性 suffix'],
            'testsuite に directory のほかの子がある' => [
                self::config('<directory>tests</directory><exclude>tests/Slow</exclude>'),
                '<exclude>',
            ],
            'groups を持つ' => [
                self::config('<directory>tests</directory>', '<groups><exclude><group>slow</group></exclude></groups>'),
                '<groups>',
            ],
        ];
    }

    #[DataProvider('unreadableConfigs')]
    public function test_a_private_config_out_of_the_allowed_shape_fails_with_the_reason(
        string $config,
        string $reason,
    ): void {
        $root = $this->temporaryTree(true, true, $config);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($reason);

        self::privateTestDirectories($root);
    }

    /**
     * 根 $root の、手元の phpunit の設定の suite が挙げるディレクトリ(設定のファイルの実体の置き場から解いた実体の道。設定が無ければ空)と、
     * {@see SourceDirectories::TESTS} のうち手元の分で在るディレクトリ(実体の道)。どちらも並べ替えて重なりを除く。
     *
     * @return array{list<string>, list<string>}
     *
     * @throws UnexpectedValueException 手元の設定が XML として読めないとき・許す形の外のとき
     */
    private static function privateTestDirectories(string $root): array
    {
        $listed = self::listedPrivateSuites($root);
        $private = array_filter(
            SourceDirectories::TESTS,
            fn (string $directory): bool => str_starts_with($directory, SourceDirectories::PRIVATE_ROOT.'/'),
        );
        $present = array_filter(
            array_map(fn (string $directory): string|false => realpath($root.'/'.$directory), $private),
            fn (string|false $path): bool => $path !== false && is_dir($path),
        );

        return [$listed, self::sorted(array_values($present))];
    }

    /**
     * 根 $root の手元の phpunit の設定の suite が挙げるディレクトリ(実体の道。在らなければ解いた道のまま)。設定が無ければ空。
     *
     * @return list<string>
     *
     * @throws UnexpectedValueException 設定が XML として読めないとき・許す形の外のとき
     */
    private static function listedPrivateSuites(string $root): array
    {
        $config = realpath($root.'/'.SourceDirectories::PRIVATE_PHPUNIT_CONFIG);

        if ($config === false) {
            return [];
        }

        $xpath = XmlFile::xpath($config);
        self::assertAllowedShape($config, $xpath);
        $directories = XmlFile::values($xpath, '/phpunit/testsuites/testsuite/directory');

        return self::sorted(array_map(
            function (string $directory) use ($config): string {
                $path = str_starts_with($directory, '/') ? $directory : dirname($config).'/'.$directory;

                return realpath($path) ?: $path;
            },
            $directories,
        ));
    }

    /**
     * 手元の設定 $config が許す形(どの testsuite も子は属性の無い directory だけ・groups を持たない。source は見ない)に収まる。
     *
     * @throws UnexpectedValueException 許す形の外のとき(どの要素か属性かを名指す)
     */
    private static function assertAllowedShape(string $config, DOMXPath $xpath): void
    {
        $outside = [
            '/phpunit/groups' => '<%s> を持つ',
            '/phpunit/testsuites/testsuite/*[not(self::directory)]' => 'testsuite に <%s> がある',
            '/phpunit/testsuites/testsuite/directory/@*' => '<directory> に属性 %s がある',
        ];

        foreach ($outside as $expression => $reason) {
            $nodes = $xpath->query($expression);
            $node = $nodes === false ? null : $nodes->item(0);

            if ($node !== null) {
                $where = sprintf($reason, $node->nodeName);

                throw new UnexpectedValueException("手元の phpunit の設定 {$config} が許す形の外: {$where}");
            }
        }
    }

    /** 一時の木の手元の phpunit の設定(suite の子 $suite と、testsuites の後ろに置く要素 $after) */
    private static function config(string $suite, string $after = ''): string
    {
        return sprintf(self::PRIVATE_CONFIG, $suite, $after);
    }

    /**
     * 一時の木の根。根の ai-private のリンク($linked)が在れば、木の外の実体の置き場に、手元の tests($tests)と手元の設定 $config
     * (null = 置かない)を作る。
     */
    private function temporaryTree(bool $linked, bool $tests, ?string $config): string
    {
        $temporary = TempDirectory::create(self::root().'/build/fixtures', 'source-directories');
        $this->tree = $temporary.'/tree';

        if (! $linked) {
            return $this->tree = TempDirectory::create($temporary, 'tree');
        }

        $source = $temporary.'/source';
        $target = $temporary.'/linked';
        mkdir($source);
        mkdir($target);

        if ($tests) {
            mkdir($target.'/tests');
        }

        if ($config !== null) {
            file_put_contents($target.'/phpunit.xml', $config);
        }

        $this->tree = LinkedTree::create($temporary, 'tree', $source, SourceDirectories::PRIVATE_ROOT, $target);

        return $this->tree;
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private static function sorted(array $paths): array
    {
        $unique = array_values(array_unique($paths));
        sort($unique);

        return $unique;
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
