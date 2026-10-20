<?php

namespace Tests\Architecture;

use Tests\Architecture\Engine\Directories;
use Tests\Architecture\Engine\ProcessResult;
use Tests\Architecture\Engine\SuiteRuns;
use Tests\Architecture\Engine\TempDirectory;
use Tests\Architecture\Engine\Values;
use Tests\Architecture\Policy\LedgerChecks;
use Tests\Architecture\Policy\LoopholeChecks;
use Tests\Architecture\Policy\Verification;
use UnexpectedValueException;

/**
 * M 照合(scripts/ci/verify)の自己証明 = #1・#6 の除外と抑制(台帳⑧)・⑤。
 *
 * ⑤ の fixture は台帳の実物から作る。
 * fixture = tests/Architecture/fixtures/verify/{violating,passing}/ の junit・phpunit.xml・phpstan.neon と、build/fixtures に一時生成するもの
 * (抑制トークンを含む PHP・依存の定義)。共通の手順は {@see VerifySelfProofTestCase}。
 * 流用元 = chatpinet_2 の同名のテスト(④⑥・保護ファイルの分を削った)。
 */
final class VerifySelfProofTest extends VerifySelfProofTestCase
{
    private string $appRoot;

    private string $workRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->appRoot = self::appRoot();
        $this->workRoot = TempDirectory::create($this->appRoot.'/build/fixtures', 'verify');
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->workRoot);

        parent::tearDown();
    }

    public function test_detects_a_suite_that_did_not_run(): void
    {
        $this->assertDetects(SuiteRuns::NOT_RUN, 'Concurrency', null, $this->suiteRuns('violating/suite-not-run'));
    }

    public function test_detects_a_skipped_suite(): void
    {
        $this->assertDetects(SuiteRuns::SKIPPED, 'Unit', null, $this->suiteRuns('violating/suite-skipped'));
    }

    public function test_passes_when_every_suite_ran_without_skips(): void
    {
        $this->assertClean($this->suiteRuns('passing/suites'));
    }

    public function test_passes_a_merged_junit_without_suite_names(): void
    {
        $this->assertClean($this->suiteRuns('passing/suites-merged'));
    }

    public function test_rejects_a_testcase_in_a_directory_that_two_suites_scan(): void
    {
        $this->expectException(UnexpectedValueException::class);

        $this->suiteRuns('violating/suite-overlap');
    }

    public function test_detects_an_unregistered_suppression_annotation(): void
    {
        $this->writeSources(['app/Suppressed.php' => "<?php\n\n\$length = strlen('x'); // @phpstan-ignore function.notFound\n"]);

        $this->assertDetects('@phpstan-ignore', 'app/Suppressed.php', 3, self::verdict($this->loopholes($this->workRoot)->suppressionTokens()));
    }

    public function test_detects_an_unregistered_ast_grep_suppression_annotation(): void
    {
        $this->writeSources(['app/Suppressed.php' => "<?php\n\n\$page = 'A.B'; // ast-grep-ignore\n"]);

        $this->assertDetects('ast-grep-ignore', 'app/Suppressed.php', 3, self::verdict($this->loopholes($this->workRoot)->suppressionTokens()));
    }

    public function test_detects_an_unregistered_suppression_annotation_in_a_php_file_without_extension(): void
    {
        $this->writeSources(['artisan' => "#!/usr/bin/env php\n<?php\n\n\$length = strlen('x'); // @phpstan-ignore function.notFound\n"]);

        $this->assertDetects('@phpstan-ignore', 'artisan', 4, self::verdict($this->loopholes($this->workRoot)->suppressionTokens()));
    }

    public function test_passes_tokens_in_string_literals_and_under_registered_paths(): void
    {
        $this->writeSources([
            'app/Literal.php' => "<?php\n\n\$token = '@phpstan-ignore';\n",
            'tests/Architecture/fixtures/Registered.php' => "<?php\n\n\$length = strlen('x'); // @phpstan-ignore function.notFound\n",
            'vendor/acme/Registered.php' => "<?php\n\n\$length = strlen('x'); // @phpstan-ignore function.notFound\n",
        ]);

        $this->assertClean(self::verdict($this->loopholes($this->workRoot)->suppressionTokens()));
    }

    public function test_detects_an_unregistered_phpstan_exclude_path(): void
    {
        $this->assertDetects('excludePaths', 'unregistered/**', null, self::verdict($this->loopholes(self::fixture('violating/phpstan-exclude'))->phpstan()));
    }

    public function test_passes_a_registered_phpstan_exclude_path(): void
    {
        $this->assertClean(self::verdict($this->loopholes(self::fixture('passing/phpstan-exclude'))->phpstan()));
    }

    public function test_detects_a_direct_dependency_missing_from_the_ledger(): void
    {
        $this->writeManifests([...self::dependencyLedger('composer'), 'acme/unregistered'], self::dependencyLedger('npm'));

        $this->assertDetects(LedgerChecks::EXTRA, 'acme/unregistered', null, self::verdict($this->ledgers()->composerDependencies()));
    }

    public function test_detects_a_registered_dependency_that_is_not_required(): void
    {
        [$dropped, $rest] = self::withoutFirst(self::dependencyLedger('composer'));
        $this->writeManifests($rest, self::dependencyLedger('npm'));

        $this->assertDetects(LedgerChecks::MISSING, $dropped, null, self::verdict($this->ledgers()->composerDependencies()));
    }

    public function test_passes_dependencies_equal_to_the_ledger(): void
    {
        $this->writeManifests(self::dependencyLedger('composer'), self::dependencyLedger('npm'));

        $this->assertClean(self::verdict($this->ledgers()->composerDependencies()));
        $this->assertClean(self::verdict($this->ledgers()->npmDependencies()));
    }

    private function suiteRuns(string $fixture): ProcessResult
    {
        return self::verdict($this->ledgers($this->copiedFixture($fixture))->suiteRuns());
    }

    /**
     * fixture を一時ディレクトリへ写し、ファイルの中の `__ROOT__` をそのディレクトリの絶対パスに置き換えて、パスを返す。
     * junit の testcase の file は対象のルートからの相対パスで suite に照合される(SuiteRuns)ので、fixture の junit は `__ROOT__/tests/<suite>/…` と書く。
     */
    private function copiedFixture(string $fixture): string
    {
        $target = TempDirectory::create($this->workRoot, 'fixture');

        foreach (Directories::fileNames(self::fixture($fixture)) as $name) {
            $content = file_get_contents(self::fixture($fixture).'/'.$name);
            self::assertIsString($content);
            file_put_contents($target.'/'.$name, str_replace('__ROOT__', $target, $content));
        }

        return $target;
    }

    private function loopholes(string $targetRoot): LoopholeChecks
    {
        return new LoopholeChecks($this->appRoot, $targetRoot, self::config('guardrails_allow')['exclusions'] ?? null);
    }

    private function ledgers(?string $targetRoot = null): LedgerChecks
    {
        return new LedgerChecks($targetRoot ?? $this->workRoot, self::config('guardrails_ledger'));
    }

    /**
     * 抑制トークンの走査ルートにファイルを書く。Policy の走査パスのうち走査ルートそのもの(`.`)以外はファイル(artisan)なので、
     * 中身の無い PHP として先に作る($files で上書きできる)。
     *
     * @param  array<string, string>  $files
     */
    private function writeSources(array $files): void
    {
        foreach (Verification::SUPPRESSION_PATHS as $path) {
            if ($path !== '.') {
                file_put_contents($this->workRoot.'/'.$path, "<?php\n");
            }
        }

        foreach ($files as $path => $content) {
            if (! is_dir(dirname($this->workRoot.'/'.$path))) {
                mkdir(dirname($this->workRoot.'/'.$path), 0777, true);
            }

            file_put_contents($this->workRoot.'/'.$path, $content);
        }
    }

    /**
     * @param  list<string>  $composer
     * @param  list<string>  $npm
     */
    private function writeManifests(array $composer, array $npm): void
    {
        $versions = fn (array $names): array => array_fill_keys($names, '*');

        file_put_contents($this->workRoot.'/'.Verification::COMPOSER_JSON, json_encode(['require' => $versions($composer)], JSON_THROW_ON_ERROR));
        file_put_contents($this->workRoot.'/'.Verification::PACKAGE_JSON, json_encode(['devDependencies' => $versions($npm)], JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<string>
     */
    private static function dependencyLedger(string $manager): array
    {
        $dependencies = Values::table(self::config('guardrails_ledger')['dependencies'] ?? null, 'dependencies');

        return Values::strings($dependencies[$manager] ?? null, "dependencies.{$manager}");
    }

    /**
     * @param  list<string>  $items
     * @return array{string, list<string>}
     */
    private static function withoutFirst(array $items): array
    {
        self::assertNotSame([], $items, '台帳が空で、不足の fixture を作れない');

        return [$items[0], array_slice($items, 1)];
    }
}
