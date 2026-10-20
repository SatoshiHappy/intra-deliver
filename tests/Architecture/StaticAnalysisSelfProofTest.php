<?php

namespace Tests\Architecture;

/**
 * S 静的解析(#2 = level 8・エラー 0)の自己証明。
 * level 8 でだけ出る指摘(nullable への呼び出し)で、設定のレベルが 8 であることも確かめる。
 */
final class StaticAnalysisSelfProofTest extends PhpStanSelfProofTestCase
{
    public function test_level_8_detects_a_method_call_on_a_nullable_type(): void
    {
        $this->assertDetects('method.nonObject', 'StaticAnalysis/violating/NullableAndReturnType.php', 11, self::analyse('StaticAnalysis/violating'));
    }

    public function test_detects_a_wrong_return_type(): void
    {
        $this->assertDetects('return.type', 'StaticAnalysis/violating/NullableAndReturnType.php', 16, self::analyse('StaticAnalysis/violating'));
    }

    public function test_passes_type_safe_code(): void
    {
        $this->assertClean(self::analyse('StaticAnalysis/passing'));
    }
}
