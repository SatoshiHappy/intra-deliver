<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Architecture\Engine\CheckResult;
use Tests\Architecture\Engine\ExclusionLedger;
use Tests\Architecture\Engine\Finding;
use Tests\Architecture\Engine\GitleaksConfig;
use Tests\Architecture\Engine\Paths;
use Tests\Architecture\Engine\PhpStanParameters;
use Tests\Architecture\Engine\PhpUnitConfig;
use Tests\Architecture\Engine\PintConfig;
use Tests\Architecture\Engine\SourceTokens;
use Tests\Architecture\Engine\TempDirectory;
use UnexpectedValueException;

/**
 * M 照合の Engine のうち、自己証明(VerifySelfProofTest)が通らない変換(パスの正規化・各ツールの除外の読み方・台帳の登録の判定)を確かめる。
 * 流用元 = chatpinet_2 の同名のテスト(③ 認証不要ルートの分を削った)。
 */
final class VerifyEngineTest extends TestCase
{
    private string $workRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workRoot = TempDirectory::create(dirname(__DIR__, 2).'/build/fixtures', 'verify-engine');
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->workRoot);

        parent::tearDown();
    }

    public function test_single_star_stays_within_one_level_and_double_star_crosses_levels(): void
    {
        $this->assertTrue(Paths::matches('vendor/*', 'vendor/a'));
        $this->assertFalse(Paths::matches('vendor/*', 'vendor/a/b'));
        $this->assertTrue(Paths::matches('vendor/**', 'vendor/a/b'));
        $this->assertTrue(Paths::matches('.github/workflows/ci-*.yml', '.github/workflows/ci-main.yml'));
        $this->assertFalse(Paths::matches('.github/workflows/ci-*.yml', '.github/workflows/other-main.yml'));
    }

    public function test_a_glob_double_star_with_its_slash_also_matches_no_level(): void
    {
        $this->assertTrue(Paths::matchesGlob('resources/js/**/*.test.ts', 'resources/js/a.test.ts'));
        $this->assertTrue(Paths::matchesGlob('resources/js/**/*.test.ts', 'resources/js/lib/Records/a.test.ts'));
        $this->assertFalse(Paths::matchesGlob('resources/js/**/*.test.ts', 'resources/a.test.ts'));
        $this->assertFalse(Paths::matchesGlob('resources/js/**/*.test.ts', 'resources/js/lib/a.ts'));
        $this->assertFalse(Paths::matches('resources/js/**/*.test.ts', 'resources/js/a.test.ts'));
    }

    public function test_paths_are_normalized_and_made_relative(): void
    {
        $this->assertSame('a/c', Paths::normalize('./a//b/../c/'));
        $this->assertSame('../../x', Paths::normalize('../../x'));
        $this->assertSame('../../outside/**', Paths::relative('/r/apps/app', '/r/outside/**'));
        $this->assertSame('tests/fixtures/**', Paths::relative('/r/apps/app', '/r/apps/app/tests/fixtures/**'));
    }

    public function test_an_exclusion_must_be_registered_in_every_check_it_removes(): void
    {
        $ledger = ExclusionLedger::fromArray([
            'B1' => [['path' => 'fixtures/**', 'reason' => 'r']],
            '#2' => [['path' => 'fixtures/**/', 'reason' => 'r']],
            '#3' => [['rule' => 'fixtures/**', 'reason' => 'r']],
        ],
            '#7',
        );

        $this->assertTrue($ledger->registers(['B1', '#2'], 'path', './fixtures/**'));
        $this->assertFalse($ledger->registers(['B1', '#2', '#3'], 'path', 'fixtures/**'));
        $this->assertFalse($ledger->registers(['#9'], 'path', 'fixtures/**'));
        $this->assertFalse($ledger->registers([], 'path', 'fixtures/**'));
        $this->assertFalse($ledger->registers(['B1'], 'unknown', 'fixtures/**'));
    }

    public function test_an_occurrence_is_covered_by_a_path_pattern_or_a_rule(): void
    {
        $ledger = ExclusionLedger::fromArray(['#6' => [
            ['path' => 'tests/fixtures/**', 'reason' => 'r'],
            ['rule' => '--filter', 'reason' => 'r'],
        ]],
            '#7',
        );

        $occurrences = [
            new Finding('token', 'tests/fixtures/a/B.php', 3),
            new Finding('--filter', 'scripts/ci/test', 4),
            new Finding('token', 'app/C.php', 5),
        ];

        $this->assertEquals([new Finding('token', 'app/C.php', 5)], $ledger->uncovered(['#6'], $occurrences));
    }

    public function test_a_ledger_row_must_have_exactly_one_kind_and_a_reason(): void
    {
        $this->expectException(UnexpectedValueException::class);

        ExclusionLedger::fromArray(['#6' => [['path' => 'a', 'rule' => 'b', 'reason' => 'r']]], '#7');
    }

    public function test_a_scoped_row_matches_only_lookups_with_the_same_scope(): void
    {
        $ledger = ExclusionLedger::fromArray(['#11' => [
            ['pattern' => 'all$', 'reason' => 'r'],
            ['pattern' => 'dir$', 'scope' => 'dir', 'reason' => 'r'],
        ]],
            '#7',
        );

        $this->assertTrue($ledger->registers(['#11'], 'pattern', 'all$'));
        $this->assertFalse($ledger->registers(['#11'], 'pattern', 'all$', 'dir'));
        $this->assertTrue($ledger->registers(['#11'], 'pattern', 'dir$', 'dir'));
        $this->assertFalse($ledger->registers(['#11'], 'pattern', 'dir$'));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidScopes(): array
    {
        return [
            'uppercase' => ['Dir'],
            'empty' => [''],
            'another word' => ['git'],
            'null' => [null],
        ];
    }

    #[DataProvider('invalidScopes')]
    public function test_a_scope_other_than_the_allowed_values_is_an_invalid_row(mixed $scope): void
    {
        $this->expectException(UnexpectedValueException::class);

        ExclusionLedger::fromArray(['#11' => [['pattern' => 'a', 'scope' => $scope, 'reason' => 'r']]], '#7');
    }

    public function test_a_row_with_an_unknown_key_is_an_invalid_row(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Scope');

        ExclusionLedger::fromArray(['#11' => [['pattern' => 'a', 'Scope' => 'dir', 'reason' => 'r']]], '#7');
    }

    public function test_gitleaks_extend_path_is_read_as_written(): void
    {
        $this->assertSame('.gitleaks.toml', GitleaksConfig::extendPath(['extend' => ['path' => '.gitleaks.toml']]));
        $this->assertNull(GitleaksConfig::extendPath(['extend' => ['useDefault' => true]]));
        $this->assertNull(GitleaksConfig::extendPath([]));
    }

    public function test_tokens_in_string_literals_are_data_and_lines_are_reported(): void
    {
        mkdir($this->workRoot.'/app');
        file_put_contents($this->workRoot.'/app/A.php', <<<'PHP'
            <?php

            $single = 'markTestSkipped';
            $double = "markTestSkipped {$single}";
            $now = <<<'TXT'
            markTestSkipped
            TXT;
            $this->markTestSkipped();
            PHP);

        $this->assertEquals(
            [new Finding('markTestSkipped', 'app/A.php', 8)],
            SourceTokens::inPhpCode($this->workRoot, ['app'], ['markTestSkipped']),
        );
    }

    public function test_option_values_are_read_with_or_without_equals(): void
    {
        mkdir($this->workRoot.'/ci');
        file_put_contents($this->workRoot.'/ci/run', "tool --baseline-path .base.json \\\n  --baseline-path=\"other.json\"\n");

        $this->assertSame(
            [
                ['file' => 'ci/run', 'line' => 1, 'value' => '.base.json'],
                ['file' => 'ci/run', 'line' => 2, 'value' => 'other.json'],
            ],
            SourceTokens::optionValues($this->workRoot, ['ci/run'], '--baseline-path'),
        );
    }

    public function test_pint_exclusions_are_normalized_to_ledger_patterns(): void
    {
        $this->assertSame(
            [
                ['key' => 'exclude', 'kind' => 'path', 'value' => 'reference/**'],
                ['key' => 'notPath', 'kind' => 'path', 'value' => 'a/b.php'],
                ['key' => 'notName', 'kind' => 'path', 'value' => '**/*.blade.php'],
            ],
            PintConfig::exclusions(['preset' => 'laravel', 'exclude' => ['reference/'], 'notPath' => ['a/./b.php'], 'notName' => ['*.blade.php']]),
        );
    }

    public function test_gitleaks_exclusions_cover_every_allowlist_and_disabled_rules(): void
    {
        $config = [
            'extend' => ['useDefault' => true, 'disabledRules' => ['generic-api-key']],
            'allowlist' => ['paths' => ['legacy$']],
            'allowlists' => [['description' => 'd', 'paths' => ['(?:^|/)vendor$'], 'regexes' => ['abc'], 'future' => ['x']]],
            'rules' => [['id' => 'custom', 'allowlists' => [['stopwords' => ['example']]]]],
        ];

        $this->assertSame(
            [
                ['key' => 'paths', 'kind' => 'pattern', 'value' => 'legacy$'],
                ['key' => 'paths', 'kind' => 'pattern', 'value' => '(?:^|/)vendor$'],
                ['key' => 'regexes', 'kind' => 'rule', 'value' => 'abc'],
                ['key' => 'future', 'kind' => 'unknown', 'value' => '["x"]'],
                ['key' => 'stopwords', 'kind' => 'rule', 'value' => 'example'],
                ['key' => 'disabledRules', 'kind' => 'rule', 'value' => 'generic-api-key'],
            ],
            GitleaksConfig::exclusions($config),
        );
    }

    public function test_phpstan_exclusions_include_every_exclude_path_and_ignored_error(): void
    {
        $parameters = [
            'excludePaths' => ['analyseAndScan' => ['/r/app/a/**'], 'analyse' => ['/r/b']],
            'ignoreErrors' => ['#message#', ['identifiers' => ['x.y', 'x.z'], 'path' => '/r/app/c.php'], ['path' => '/r/app/d.php']],
        ];

        $this->assertSame(
            [
                ['key' => 'excludePaths', 'kind' => 'path', 'value' => 'a/**'],
                ['key' => 'excludePaths', 'kind' => 'path', 'value' => '../b'],
                ['key' => 'ignoreErrors', 'kind' => 'rule', 'value' => '#message#'],
                ['key' => 'ignoreErrors', 'kind' => 'rule', 'value' => 'x.y'],
                ['key' => 'ignoreErrors', 'kind' => 'rule', 'value' => 'x.z'],
                ['key' => 'ignoreErrors', 'kind' => 'rule', 'value' => '{"path":"\/r\/app\/d.php"}'],
            ],
            PhpStanParameters::exclusions($parameters, '/r/app'),
        );
    }

    public function test_phpunit_exclusions_cover_suites_groups_and_source(): void
    {
        mkdir($this->workRoot.'/tests/Slow', 0777, true);
        file_put_contents($this->workRoot.'/phpunit.xml', <<<'XML'
            <phpunit>
                <testsuites><testsuite name="Unit"><directory>tests</directory><exclude>tests/Slow</exclude><exclude>tests/A.php</exclude></testsuite></testsuites>
                <groups><exclude><group>slow</group></exclude></groups>
                <source><include><directory>app</directory></include><exclude><directory>app/Legacy</directory></exclude></source>
            </phpunit>
            XML);

        $this->assertSame(
            [
                ['key' => 'testsuite.exclude', 'kind' => 'path', 'value' => 'tests/Slow/**'],
                ['key' => 'testsuite.exclude', 'kind' => 'path', 'value' => 'tests/A.php'],
                ['key' => 'groups.exclude', 'kind' => 'rule', 'value' => 'slow'],
                ['key' => 'source.exclude', 'kind' => 'path', 'value' => 'app/Legacy/**'],
            ],
            PhpUnitConfig::load($this->workRoot.'/phpunit.xml')->exclusions(),
        );
    }

    public function test_target_narrowing_settings_are_read_as_written(): void
    {
        file_put_contents($this->workRoot.'/phpunit.xml', <<<'XML'
            <phpunit><testsuites>
                <testsuite name="Unit"><directory>./tests/Unit/</directory><directory suffix="Only.php">tests/Extra</directory></testsuite>
                <testsuite name="Feature"/>
            </testsuites></phpunit>
            XML);

        $this->assertSame(
            ['Unit' => ['tests/Unit', 'tests/Extra [suffix=Only.php]'], 'Feature' => []],
            PhpUnitConfig::load($this->workRoot.'/phpunit.xml')->suiteDirectories(),
        );
        $this->assertSame(
            [['key' => 'in', 'value' => 'app'], ['key' => 'extend', 'value' => 'base.json']],
            PintConfig::narrowing(['preset' => 'laravel', 'in' => 'app', 'extend' => 'base.json']),
        );
        $this->assertSame(['app', '../x'], PhpStanParameters::analysedPaths(['paths' => ['/r/app/app', '/r/x']], '/r/app'));
    }

    public function test_check_result_line_shows_the_summary_or_every_finding(): void
    {
        $this->assertSame('OK  ⑤ — 15 個', (new CheckResult([], '15 個'))->line('⑤'));
        $this->assertSame(
            'NG  ⑤: ledger.extra acme/a / token app/A.php:3',
            (new CheckResult([new Finding('ledger.extra', 'acme/a', null), new Finding('token', 'app/A.php', 3)], ''))->line('⑤'),
        );
    }
}
