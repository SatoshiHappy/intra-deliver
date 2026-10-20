<?php

namespace Tests\Architecture;

use Tests\Architecture\Engine\TempDirectory;
use Tests\Architecture\Policy\LoopholeChecks;
use Tests\Architecture\Policy\Verification;

/**
 * M 照合の #6 対象の絞り込み(phpstan の paths・phpunit の suite の directory・pint の走査対象)の自己証明。vp test の分は {@see VerifyViteTestSelfProofTest}。
 *
 * 違反 = tests/Architecture/fixtures/verify/violating/ の phpstan.neon(paths をアプリのルート全体から app に狭める)・phpunit.xml(directory を狭める・属性で絞る)と、
 * build/fixtures に一時生成する pint.json(in で絞る)。正常 = アプリの現行の設定。共通の手順は {@see VerifySelfProofTestCase}。
 */
final class VerifyTargetsSelfProofTest extends VerifySelfProofTestCase
{
    private string $workRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workRoot = TempDirectory::create(self::appRoot().'/build/fixtures', 'verify-targets');
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->workRoot);

        parent::tearDown();
    }

    public function test_detects_phpstan_paths_without_a_policy_directory(): void
    {
        $this->assertDetects('paths', '.', null, self::verdict(self::loopholes(self::fixture('violating/phpstan-paths'))->phpstanTargets()));
    }

    public function test_detects_a_narrowed_suite_directory(): void
    {
        $this->assertDetects('directory.missing', 'Unit tests/Unit', null, self::verdict(self::loopholes(self::fixture('violating/suite-directory'))->phpunitTargets()));
    }

    public function test_detects_a_suite_directory_narrowed_by_an_attribute(): void
    {
        $this->assertDetects(
            'directory.extra',
            'Feature tests/Feature [suffix=OnlyTest.php]',
            null,
            self::verdict(self::loopholes(self::fixture('violating/suite-directory'))->phpunitTargets()),
        );
    }

    public function test_detects_pint_targets_narrowed_by_in(): void
    {
        file_put_contents($this->workRoot.'/'.Verification::PINT_CONFIG, json_encode(['preset' => 'laravel', 'in' => ['app']], JSON_THROW_ON_ERROR));

        $this->assertDetects('in', 'app', null, self::verdict(self::loopholes($this->workRoot)->pintTargets()));
    }

    private static function loopholes(string $targetRoot): LoopholeChecks
    {
        return new LoopholeChecks(self::appRoot(), $targetRoot, self::config('guardrails_allow')['exclusions'] ?? null);
    }
}
