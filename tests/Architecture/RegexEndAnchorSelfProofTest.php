<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 正規表現の $(PHPStan の自作規則 regexEndAnchor。docs の 設計/コード構成/検査/README.md 6.2)の自己証明。
 */
final class RegexEndAnchorSelfProofTest extends PhpStanSelfProofTestCase
{
    private const RULE = 'regexEndAnchor';

    private const VIOLATING = 'RegexEndAnchor/violating';

    public function test_detects_a_bare_dollar_in_a_pattern_function(): void
    {
        $this->assertDetects(self::RULE, self::VIOLATING.'/BareDollar.php', 14, self::analyse(self::VIOLATING));
    }

    public function test_detects_a_bare_dollar_in_a_pattern_array(): void
    {
        $this->assertDetects(self::RULE, self::VIOLATING.'/BareDollar.php', 19, self::analyse(self::VIOLATING));
    }

    public function test_detects_a_bare_dollar_in_a_keyed_pattern(): void
    {
        $this->assertDetects(self::RULE, self::VIOLATING.'/BareDollar.php', 24, self::analyse(self::VIOLATING));
    }

    public function test_detects_a_bare_dollar_in_an_assertion(): void
    {
        $this->assertDetects(self::RULE, self::VIOLATING.'/BareDollar.php', 29, self::analyse(self::VIOLATING));
    }

    public function test_detects_a_bare_dollar_after_a_scoped_inline_modifier(): void
    {
        $this->assertDetects(self::RULE, self::VIOLATING.'/BareDollar.php', 34, self::analyse(self::VIOLATING));
    }

    public function test_detects_a_bare_dollar_after_an_inline_modifier_that_turns_m_off(): void
    {
        $this->assertDetects(self::RULE, self::VIOLATING.'/BareDollar.php', 35, self::analyse(self::VIOLATING));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function turnedOffModifierLines(): array
    {
        return ['全体の m を (?-m) で外す' => [40], '先頭で付けて外す' => [41], '途中で外す' => [42], '全体の x を (?-x) で外す' => [43]];
    }

    #[DataProvider('turnedOffModifierLines')]
    public function test_detects_a_bare_dollar_when_an_inline_modifier_turns_m_or_x_off(int $line): void
    {
        $this->assertDetects(self::RULE, self::VIOLATING.'/BareDollar.php', $line, self::analyse(self::VIOLATING));
    }

    public function test_detects_a_bare_dollar_through_a_constant(): void
    {
        $this->assertDetects(self::RULE, self::VIOLATING.'/ThroughConstant.php', 14, self::analyse(self::VIOLATING));
    }

    public function test_detects_a_bare_dollar_even_with_a_suppression_comment(): void
    {
        $this->assertDetects(self::RULE, self::VIOLATING.'/Suppressed.php', 13, self::analyse(self::VIOLATING));
    }

    public function test_passes_anchors_to_the_end_and_dollars_that_are_not_anchors(): void
    {
        $this->assertClean(self::analyse('RegexEndAnchor/passing'));
    }
}
