<?php

namespace Tests\Architecture;

use Tests\Architecture\Engine\ExclusionLedger;
use Tests\Architecture\Engine\ExclusionLedgerFile;
use Tests\Architecture\Engine\Finding;
use Tests\Architecture\Engine\InternalDependencies;
use Tests\Architecture\Engine\LineLengths;
use Tests\Architecture\Engine\LinkedTree;
use Tests\Architecture\Engine\PhpStanParameters;
use Tests\Architecture\Engine\Placement;
use Tests\Architecture\Engine\Process;
use Tests\Architecture\Engine\ProcessResult;
use Tests\Architecture\Engine\SourceTokens;
use Tests\Architecture\Engine\TempDirectory;
use Tests\Architecture\Engine\Values;
use Tests\Architecture\Policy\Baselines;
use Tests\Architecture\Policy\CodeLayout;
use Tests\Architecture\Policy\Licenses;
use Tests\Architecture\Policy\LoopholeChecks;
use Tests\Architecture\Policy\ModuleInternals;
use Tests\Architecture\Policy\SourceDirectories;
use Tests\Architecture\Policy\Verification;
use UnexpectedValueException;

/**
 * 手元だけのリポジトリへのリンク(アプリのルートの {@see SourceDirectories::PRIVATE_ROOT})の先のファイルに、道で対象を選ぶ PHP の検査が
 * 木の中のファイルと同じに当たることの自己証明(docs の ADR 0039 の決定 8、設計/コード構成/検査/README.md 6.2・6.3)。
 * - #6 (a) 抑制トークンの走査(手元のリポジトリのリンクだけをたどる)。除外で配下がすべて外れるディレクトリには今のとおり入らない。
 *   一覧の外のディレクトリのリンクと、手元のリポジトリの中のリンク(祖先を指す循環)は、たどらずに落ちる。除外の中のリンク(storage:link が
 *   作る public/storage)は、抑制・行の長さ・内部への依存の走査のどれでも落とさない。その除外の語を phpstan は、道が無くても読み込む
 * - ほかのモジュールの内部への依存の数え(リンクの先から移行中のモジュールの内部へ、木の側からリンクの先だけに在る内部のクラスへ)
 * - 行の長さ(PHP)
 * - 置き場の規則(app の直下に置けるもの)
 *
 * 一時の木 = tests/Architecture/fixtures/PrivateLink/tree の写しと、木の外の同 linked を指すリンク(build/fixtures の下にテストのたびに作る)。
 * 判定と対象選択は Policy の実物。台帳⑧は #6 と除外の中のリンクの行が実物、ほかは一時の木の config/guardrails_allow.php(除外なし)。
 */
final class PrivateLinkScanSelfProofTest extends VerifySelfProofTestCase
{
    /** 依存物の置き場(台帳⑧ #6 の vendor/**)の中の、読めないディレクトリ(中に入ると走査が読めずに投げる) */
    private const LOCKED = 'vendor/acme/locked';

    /** 一覧の外のディレクトリのリンク */
    private const OTHER_LINK = 'public/other';

    /** 除外(台帳⑧の public/storage/**)の中のディレクトリのリンク(storage:link が作る形) */
    private const STORAGE_LINK = 'public/storage';

    /** 手元のリポジトリの中の、祖先(手元のリポジトリの根)を指すリンク */
    private const INNER_LINK = 'app/loop';

    private string $tree;

    /** 中にリンクを置いた、手元のリポジトリの写し(使ったテストだけ) */
    private ?string $target = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tree = LinkedTree::create(
            self::appRoot().'/build/fixtures',
            'private-link-scan',
            __DIR__.'/fixtures/PrivateLink/tree',
            SourceDirectories::PRIVATE_ROOT,
            __DIR__.'/fixtures/PrivateLink/linked',
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tree.'/'.self::LOCKED)) {
            chmod($this->tree.'/'.self::LOCKED, 0777);
        }

        LinkedTree::remove($this->tree, [SourceDirectories::PRIVATE_ROOT, self::OTHER_LINK, self::STORAGE_LINK]);

        if ($this->target !== null) {
            LinkedTree::remove($this->target, [self::INNER_LINK]);
        }

        parent::tearDown();
    }

    public function test_counts_suppression_tokens_behind_the_link(): void
    {
        $this->assertDetects(
            '@phpstan-ignore',
            'ai-private/app/Support/Suppressed.php',
            12,
            self::verdict($this->loopholes()->suppressionTokens()),
        );
    }

    public function test_does_not_enter_a_directory_whose_contents_are_all_excluded(): void
    {
        mkdir($this->tree.'/'.self::LOCKED, 0777, true);
        $registered = "<?php\n\n\$length = strlen('x'); // @phpstan-ignore function.notFound\n";
        file_put_contents($this->tree.'/vendor/acme/Registered.php', $registered);
        chmod($this->tree.'/'.self::LOCKED, 0);
        $this->assertFalse(is_readable($this->tree.'/'.self::LOCKED), '読めないディレクトリを作れない(root で流すと確かめられない)');

        $findings = $this->loopholes()->suppressionTokens()->findings;
        $found = array_map(fn (Finding $finding): string => $finding->describe(), $findings);

        $this->assertSame(['@phpstan-ignore ai-private/app/Support/Suppressed.php:12'], $found);
    }

    public function test_a_directory_link_outside_the_list_fails_the_scan(): void
    {
        mkdir($this->tree.'/public');
        symlink($this->tree.'/app', $this->tree.'/'.self::OTHER_LINK);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('一覧の外のディレクトリのリンクがある: '.self::OTHER_LINK.'(たどるならリンクの一覧に足し、見ないなら除外に入れる)');

        $this->loopholes()->suppressionTokens();
    }

    public function test_a_directory_link_inside_an_exclusion_does_not_fail_the_scan(): void
    {
        mkdir($this->tree.'/public');
        symlink($this->tree.'/app', $this->tree.'/'.self::STORAGE_LINK);

        $findings = $this->loopholes()->suppressionTokens()->findings;
        $found = array_map(fn (Finding $finding): string => $finding->describe(), $findings);
        $long = self::scannedFiles($this->tree, Baselines::LINE_LENGTH_PHP_FILES, Baselines::LINE_LENGTH_CHECK);
        $internal = self::scannedFiles($this->tree, ModuleInternals::filePatterns(), Baselines::MODULE_INTERNALS_CHECK);
        $pairs = ModuleInternals::dependencies()->pairs($this->tree, $internal);

        $this->assertSame(['@phpstan-ignore ai-private/app/Support/Suppressed.php:12'], $found);
        $this->assertSame(
            ['ai-private/app/Support/LongLine.php' => 1],
            LineLengths::overLimit($this->tree, $long, Baselines::MAX_LINE_LENGTH),
        );
        $this->assertSame(['Workflow → Master' => 2], InternalDependencies::counts($pairs));
    }

    /** 本物の phpstan.neon の除外の語は、道の無い木でも読み込める(fnmatch の形の道は在るかを見ない) */
    public function test_phpstan_loads_the_storage_link_exclusion_where_the_link_is_missing(): void
    {
        $parameters = PhpStanParameters::dump(self::appRoot(), self::appRoot().'/'.Verification::PHPSTAN_CONFIG);
        $paths = array_column(PhpStanParameters::exclusions($parameters, self::appRoot()), 'value');
        $this->assertContains(self::STORAGE_LINK.'/**', $paths);

        $result = self::dumpWithExclusion(self::STORAGE_LINK.'/**');

        $this->assertSame(0, $result->exitCode, $result->describe());
    }

    /** 同じ道の形でない語は、道が無いと phpstan が読み込みで止める(前の行の識別力の対照) */
    public function test_phpstan_stops_at_a_missing_exclusion_path(): void
    {
        $result = self::dumpWithExclusion(self::STORAGE_LINK);

        $this->assertNotSame(0, $result->exitCode);
        $this->assertStringContainsString('is neither a directory', $result->stdout.$result->stderr);
    }

    public function test_a_link_inside_the_private_link_is_not_followed(): void
    {
        $this->target = LinkedTree::create(
            self::appRoot().'/build/fixtures',
            'private-target',
            __DIR__.'/fixtures/PrivateLink/linked',
            self::INNER_LINK,
            '..',
        );
        LinkedTree::remove($this->tree, [SourceDirectories::PRIVATE_ROOT]);
        $this->tree = LinkedTree::create(
            self::appRoot().'/build/fixtures',
            'private-link-scan',
            __DIR__.'/fixtures/PrivateLink/tree',
            SourceDirectories::PRIVATE_ROOT,
            $this->target,
        );

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('一覧の外のディレクトリのリンクがある: '.SourceDirectories::PRIVATE_ROOT.'/'.self::INNER_LINK);

        $this->loopholes()->suppressionTokens();
    }

    public function test_counts_internal_dependencies_from_and_to_files_behind_the_link(): void
    {
        $excluded = $this->ledgerPaths(Baselines::MODULE_INTERNALS_CHECK);
        $patterns = ModuleInternals::filePatterns();
        $files = SourceTokens::globFiles($this->tree, $patterns, $excluded, SourceDirectories::LINKS);
        $pairs = ModuleInternals::dependencies()->pairs($this->tree, $files);

        $this->assertSame([
            'ai-private/app/Actions/Workflow/UsesMasterInternal.php → App\Actions\Master\DoThing',
            'app/Actions/Workflow/UsesPrivateInternal.php → App\Actions\Master\PrivateThing',
        ], array_map(fn (array $pair): string => $pair['file'].' → '.$pair['class'], $pairs));
        $this->assertSame(['Workflow → Master' => 2], InternalDependencies::counts($pairs));
    }

    public function test_counts_long_lines_behind_the_link(): void
    {
        $excluded = $this->ledgerPaths(Baselines::LINE_LENGTH_CHECK);
        $patterns = Baselines::LINE_LENGTH_PHP_FILES;
        $files = SourceTokens::globFiles($this->tree, $patterns, $excluded, SourceDirectories::LINKS);

        $this->assertSame(
            ['ai-private/app/Support/LongLine.php' => 1],
            LineLengths::overLimit($this->tree, $files, Baselines::MAX_LINE_LENGTH),
        );
    }

    public function test_applies_the_placement_rules_behind_the_link(): void
    {
        $allow = self::config('guardrails_allow');
        $baseDirectories = Values::strings($allow['base_directories'] ?? null, '台帳① base_directories');
        $rules = CodeLayout::placements($baseDirectories, ['Alpha', 'Master', 'Workflow']);

        $this->assertSame(
            ['placement.file ai-private/app/Stray.php:-'],
            array_map(fn (Finding $finding): string => $finding->describe(), Placement::findings($this->tree, $rules)),
        );
    }

    private function loopholes(): LoopholeChecks
    {
        return new LoopholeChecks(self::appRoot(), $this->tree, self::config('guardrails_allow')['exclusions'] ?? null);
    }

    /**
     * 除外 $exclusion だけを持つ最小の phpstan の設定(build/fixtures の下の一時の木。includes なし)を読み込んだ結果。
     */
    private static function dumpWithExclusion(string $exclusion): ProcessResult
    {
        $root = TempDirectory::create(self::appRoot().'/build/fixtures', 'phpstan-exclusion');
        $tmpDir = TempDirectory::create(self::appRoot().'/build/phpstan-fixtures', 'phpstan-exclusion');

        try {
            $config = "parameters:\n\tlevel: 0\n\ttmpDir: %env.PHPSTAN_FIXTURES_TMP_DIR%\n";
            file_put_contents($root.'/phpstan.neon', $config."\texcludePaths:\n\t\t- {$exclusion}\n");

            return Process::run(
                ['vendor/bin/phpstan', 'dump-parameters', '--json', '--configuration='.$root.'/phpstan.neon'],
                self::appRoot(),
                ['PHPSTAN_FIXTURES_TMP_DIR' => $tmpDir],
            );
        } finally {
            TempDirectory::remove($root);
            TempDirectory::remove($tmpDir);
        }
    }

    /**
     * 木 $tree の、パターン $patterns に合うファイル(除外 = 本物の台帳⑧(config/guardrails_allow.php)の検査 $check の分)。
     *
     * @param  list<string>  $patterns
     * @return list<string>
     */
    private static function scannedFiles(string $tree, array $patterns, string $check): array
    {
        $exclusions = self::config('guardrails_allow')['exclusions'] ?? null;
        $ledger = ExclusionLedger::fromArray($exclusions, Licenses::LEDGER_CHECK);

        return SourceTokens::globFiles($tree, $patterns, $ledger->paths([$check]), SourceDirectories::LINKS);
    }

    /**
     * @return list<string>
     */
    private function ledgerPaths(string $check): array
    {
        return ExclusionLedgerFile::read($this->tree, Licenses::LEDGER_CHECK)->paths([$check]);
    }
}
