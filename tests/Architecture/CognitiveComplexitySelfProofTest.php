<?php

namespace Tests\Architecture;

/**
 * Z 大きさのうち認知的複雑度(#3。メソッド単位で 8)の自己証明。上限ちょうど(8)は通り、9 で赤になる。
 */
final class CognitiveComplexitySelfProofTest extends PhpStanSelfProofTestCase
{
    public function test_detects_a_method_over_the_limit(): void
    {
        $this->assertDetects('complexity.functionLike', 'CognitiveComplexity/violating/NineBranches.php', 10, self::analyse('CognitiveComplexity/violating'));
    }

    public function test_passes_a_method_at_the_limit(): void
    {
        $this->assertClean(self::analyse('CognitiveComplexity/passing'));
    }
}
