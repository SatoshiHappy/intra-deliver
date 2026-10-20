<?php

namespace Tests\Architecture;

use PHPUnit\Framework\AssertionFailedError;
use Tests\Architecture\Engine\Findings;
use Tests\Architecture\Engine\ProcessResult;

/**
 * 自己証明の基底クラスの判定(assertDetects / assertClean)が、意図した規則・箇所・終了コードだけで緑になることを確かめる。
 */
final class SelfProofTestCaseTest extends SelfProofTestCase
{
    protected function findings(ProcessResult $result): array
    {
        return Findings::fromLines($result->stdout, '/^(?<rule>\S+) (?<file>\S+):(?<line>\d+)$/D');
    }

    public function test_assert_detects_passes_when_the_intended_rule_hits_the_location_with_non_zero_exit(): void
    {
        $this->assertDetects('rule-a', 'violating/Foo.php', 12, self::processResult(1, "rule-b /abs/violating/Bar.php:3\nrule-a /abs/violating/Foo.php:12"));
    }

    public function test_assert_detects_fails_when_the_tool_exits_zero(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertDetects('rule-a', 'violating/Foo.php', 12, self::processResult(0, 'rule-a /abs/violating/Foo.php:12'));
    }

    public function test_assert_detects_fails_when_only_another_rule_fails(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertDetects('rule-a', 'violating/Foo.php', 12, self::processResult(1, 'syntax-error /abs/violating/Foo.php:12'));
    }

    public function test_assert_detects_fails_when_the_rule_hits_another_line(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertDetects('rule-a', 'violating/Foo.php', 12, self::processResult(1, 'rule-a /abs/violating/Foo.php:13'));
    }

    public function test_assert_clean_passes_without_findings_and_with_zero_exit(): void
    {
        $this->assertClean(self::processResult(0, ''));
    }

    public function test_assert_clean_fails_when_a_finding_is_reported(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertClean(self::processResult(0, 'rule-a /abs/passing/Foo.php:1'));
    }

    public function test_assert_clean_fails_when_the_tool_exits_non_zero(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertClean(self::processResult(2, ''));
    }

    private static function processResult(int $exitCode, string $stdout): ProcessResult
    {
        return new ProcessResult(['tool'], $exitCode, $stdout, '');
    }
}
