<?php

namespace Tests\Architecture;

/**
 * Z 大きさのうち物理行(#3。クラス 300 / メソッド 50。コメントと空行を含む)の自己証明。
 * fixture の本体の大半はコメントと空行で、上限ちょうどは通り、1 行超えると赤になる。
 */
final class PhysicalLinesSelfProofTest extends PhpStanSelfProofTestCase
{
    public function test_detects_a_class_over_the_limit(): void
    {
        $this->assertDetects('physicalLines.class', 'PhysicalLines/violating/ClassOverLimit.php', 8, self::analyse('PhysicalLines/violating'));
    }

    public function test_detects_a_method_over_the_limit(): void
    {
        $this->assertDetects('physicalLines.method', 'PhysicalLines/violating/MethodOverLimit.php', 10, self::analyse('PhysicalLines/violating'));
    }

    public function test_detects_a_method_over_the_limit_even_with_a_suppression_comment(): void
    {
        $this->assertDetects('physicalLines.method', 'PhysicalLines/violating/SuppressedMethodOverLimit.php', 11, self::analyse('PhysicalLines/violating'));
    }

    public function test_passes_a_class_and_a_method_at_the_limit(): void
    {
        $this->assertClean(self::analyse('PhysicalLines/passing'));
    }
}
