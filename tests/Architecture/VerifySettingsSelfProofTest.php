<?php

namespace Tests\Architecture;

use Tests\Architecture\Engine\ComposerAutoload;
use Tests\Architecture\Engine\TempDirectory;
use Tests\Architecture\Engine\Values;
use Tests\Architecture\Policy\LoopholeChecks;
use Tests\Architecture\Policy\Verification;

/**
 * M 照合の #6 抜け道のうち、ツールの設定と検査の実行コマンドの照合の自己証明
 * (pint.json・phpunit.xml・phpstan.neon の除外、scripts/ci の絞り込みとベースライン、必要な規則・suite・キーの存在)。
 *
 * 違反 = build/fixtures に一時生成する設定と実行コマンド(台帳⑧に無い除外を 1 つ足す・必要なものを 1 つ外す)。
 * 正常 = アプリの現行の設定。共通の手順は {@see VerifySelfProofTestCase}。
 * 流用元に無いテスト(流用元は変換だけを VerifyEngineTest で確かめ、照合が違反で赤になることは確かめていなかった)。
 */
final class VerifySettingsSelfProofTest extends VerifySelfProofTestCase
{
    /** 見本のディレクトリ(台帳⑧ の共通の行が外すもの)と、ファイル単位の除外の照合に使うファイル(psr-4 の tests/ の下) */
    private const FIXTURE_DIRECTORY = 'tests/Architecture/fixtures';

    private const EXCLUDED_FILE = 'tests/Legacy.php';

    private string $workRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workRoot = TempDirectory::create(self::appRoot().'/build/fixtures', 'verify-settings');
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->workRoot);

        parent::tearDown();
    }

    public function test_detects_an_unregistered_pint_exclusion(): void
    {
        $this->write(Verification::PINT_CONFIG, json_encode(['preset' => 'laravel', 'exclude' => ['tests/Architecture/fixtures', 'legacy']], JSON_THROW_ON_ERROR));

        $this->assertDetects('exclude', 'legacy/**', null, self::verdict($this->loopholes()->pint()));
    }

    public function test_detects_an_unregistered_composer_classmap_exclusion(): void
    {
        mkdir($this->workRoot.'/legacy');
        $this->writeComposerAutoload(['tests/Architecture/fixtures/', 'legacy']);

        $result = self::verdict($this->loopholes()->composer());

        $this->assertDetects('exclude-from-classmap', 'legacy/**', null, $result);
    }

    public function test_detects_a_registered_path_missing_from_the_composer_classmap_exclusions(): void
    {
        $this->writeComposerAutoload([]);

        $result = self::verdict($this->loopholes()->composer());

        $this->assertDetects(Verification::PSR4_MISSING, self::FIXTURE_DIRECTORY.'/**', null, $result);
    }

    public function test_passes_a_composer_classmap_exclusion_of_a_directory_written_without_a_slash(): void
    {
        $this->writeComposerAutoload([self::FIXTURE_DIRECTORY]);

        $this->assertClean(self::verdict($this->loopholes()->composer()));
    }

    public function test_passes_a_composer_classmap_exclusion_of_a_file_registered_as_the_file(): void
    {
        $this->write(self::EXCLUDED_FILE, "<?php\n");
        $this->writeComposerAutoload([self::FIXTURE_DIRECTORY.'/', self::EXCLUDED_FILE]);

        $this->assertClean(self::verdict($this->loopholes(self::EXCLUDED_FILE)->composer()));
    }

    public function test_detects_a_composer_classmap_exclusion_of_a_file_missing_from_the_ledger(): void
    {
        $this->write(self::EXCLUDED_FILE, "<?php\n");
        $this->writeComposerAutoload([self::FIXTURE_DIRECTORY.'/', self::EXCLUDED_FILE]);

        $result = self::verdict($this->loopholes()->composer());

        $this->assertDetects('exclude-from-classmap', self::EXCLUDED_FILE, null, $result);
    }

    public function test_detects_a_registered_file_missing_from_the_composer_classmap_exclusions(): void
    {
        $this->write(self::EXCLUDED_FILE, "<?php\n");
        $this->writeComposerAutoload([self::FIXTURE_DIRECTORY.'/']);

        $result = self::verdict($this->loopholes(self::EXCLUDED_FILE)->composer());

        $this->assertDetects(Verification::PSR4_MISSING, self::EXCLUDED_FILE, null, $result);
    }

    public function test_detects_unregistered_phpunit_exclusions(): void
    {
        $this->write(Verification::PHPUNIT_CONFIG, <<<'XML'
            <phpunit>
                <testsuites><testsuite name="Unit"><directory>tests/Unit</directory><exclude>tests/Unit/SlowTest.php</exclude></testsuite></testsuites>
                <groups><exclude><group>slow</group></exclude></groups>
            </phpunit>
            XML);

        $result = self::verdict($this->loopholes()->phpunit());

        $this->assertDetects('testsuite.exclude', 'tests/Unit/SlowTest.php', null, $result);
        $this->assertDetects('groups.exclude', 'slow', null, $result);
    }

    public function test_detects_an_unregistered_phpstan_ignored_error(): void
    {
        $this->write(Verification::PHPSTAN_CONFIG, <<<'NEON'
            parameters:
                level: 8
                ignoreErrors:
                    -
                        identifier: method.nonObject
            NEON);

        $this->assertDetects('ignoreErrors', 'method.nonObject', null, self::verdict($this->loopholes()->phpstan()));
    }

    public function test_detects_a_phpstan_ignored_error_even_if_registered_in_the_ledger(): void
    {
        $this->write(Verification::PHPSTAN_CONFIG, <<<'NEON'
            parameters:
                level: 8
                ignoreErrors:
                    -
                        identifier: method.nonObject
            NEON);
        $exclusions = self::config('guardrails_allow')['exclusions'] ?? [];
        $registered = new LoopholeChecks(self::appRoot(), $this->workRoot, [
            ...$exclusions,
            ...array_fill_keys(Verification::PHPSTAN_CHECKS, [['rule' => 'method.nonObject', 'reason' => '登録しても通らないことの確かめ']]),
        ]);

        $this->assertDetects('ignoreErrors', 'method.nonObject', null, self::verdict($registered->phpstan()));
    }

    public function test_detects_a_narrowing_option_in_a_ci_script(): void
    {
        $this->write(Verification::SCRIPTS_DIRECTORY.'/test', "#!/usr/bin/env bash\nvendor/bin/phpunit --filter SmokeTest\n");

        $this->assertDetects('--filter', Verification::SCRIPTS_DIRECTORY.'/test', 2, self::verdict($this->loopholes()->scripts()));
    }

    public function test_detects_an_unregistered_baseline_in_a_ci_script(): void
    {
        $this->write(Verification::SCRIPTS_DIRECTORY.'/secrets', "#!/usr/bin/env bash\ngitleaks dir . --baseline-path other-baseline.json\n");

        $this->assertDetects(
            Verification::BASELINE_OPTION.' other-baseline.json',
            Verification::SCRIPTS_DIRECTORY.'/secrets',
            2,
            self::verdict($this->loopholes()->scripts()),
        );
    }

    public function test_detects_phpstan_settings_without_a_required_include_and_parameter(): void
    {
        $this->write(Verification::PHPSTAN_CONFIG, implode("\n", [
            'includes:',
            '    - '.self::appRoot().'/vendor/larastan/larastan/extension.neon',
            'parameters:',
            '    level: 8',
            '',
        ]));

        $result = self::verdict($this->loopholes()->phpstanSettings());

        $this->assertDetects('include', 'tests/Architecture/Engine/PhpStan/rules.neon', null, $result);
        $this->assertDetects('parameter', 'physicalLines', null, $result);
    }

    public function test_detects_a_missing_suite(): void
    {
        $this->write(Verification::PHPUNIT_CONFIG, <<<'XML'
            <phpunit>
                <testsuites>
                    <testsuite name="Architecture"><directory>tests/Architecture</directory></testsuite>
                    <testsuite name="Unit"><directory>tests/Unit</directory></testsuite>
                </testsuites>
            </phpunit>
            XML);

        $this->assertDetects('testsuite', 'Feature', null, self::verdict($this->loopholes()->phpunitSettings()));
    }

    public function test_detects_a_pint_config_without_a_preset(): void
    {
        $this->write(Verification::PINT_CONFIG, json_encode(['rules' => ['no_unused_imports' => false]], JSON_THROW_ON_ERROR));

        $this->assertDetects('key', 'preset', null, self::verdict($this->loopholes()->pintSettings()));
    }

    /**
     * 台帳は実物。$registeredFile を渡すと、PSR-4 の検査の台帳の行にそのファイルの path を足す。
     */
    private function loopholes(?string $registeredFile = null): LoopholeChecks
    {
        $exclusions = self::config('guardrails_allow')['exclusions'] ?? null;

        if ($registeredFile !== null && is_array($exclusions)) {
            foreach (Verification::PSR4_CHECKS as $check) {
                $exclusions[$check][] = ['path' => $registeredFile, 'reason' => 'ファイルの除外の照合の確かめ'];
            }
        }

        return new LoopholeChecks(self::appRoot(), $this->workRoot, $exclusions);
    }

    /**
     * 実物の composer.json の autoload・autoload-dev から両方の exclude-from-classmap を外し、autoload-dev に $excluded を置く。
     * 見本のディレクトリは作業の根に作る(値がディレクトリかを照らす木 = 作業の根)。
     *
     * @param  list<string>  $excluded
     */
    private function writeComposerAutoload(array $excluded): void
    {
        $manifest = Values::jsonFile(self::appRoot().'/'.Verification::COMPOSER_JSON);
        $autoload = [];

        foreach (['autoload', 'autoload-dev'] as $section) {
            $autoload[$section] = Values::table($manifest[$section] ?? [], "composer.json の {$section}");
            unset($autoload[$section][ComposerAutoload::EXCLUDE_KEY]);
        }

        $autoload['autoload-dev'][ComposerAutoload::EXCLUDE_KEY] = $excluded;

        if (! is_dir($this->workRoot.'/'.self::FIXTURE_DIRECTORY)) {
            mkdir($this->workRoot.'/'.self::FIXTURE_DIRECTORY, 0777, true);
        }

        $this->write(Verification::COMPOSER_JSON, json_encode($autoload, JSON_THROW_ON_ERROR));
    }

    private function write(string $path, string $content): void
    {
        if (! is_dir(dirname($this->workRoot.'/'.$path))) {
            mkdir(dirname($this->workRoot.'/'.$path), 0777, true);
        }

        file_put_contents($this->workRoot.'/'.$path, $content);
    }
}
