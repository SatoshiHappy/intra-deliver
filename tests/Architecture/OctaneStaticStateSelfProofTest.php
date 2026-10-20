<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Octane 安全 (b)の自己証明。
 * App\ の static のプロパティ(使われていない trait を含む)と、メソッド・クロージャの中の static 変数があれば赤(抑制注釈を付けても消えない)。
 * 定数・static メソッド・static クロージャ・static の戻り型と、App\ の外のクラスは通る。
 */
final class OctaneStaticStateSelfProofTest extends PhpStanSelfProofTestCase
{
    /**
     * @return array<string, array{string, string, int}>
     */
    public static function violations(): array
    {
        return [
            'static のプロパティ' => ['staticState.property', 'HoldsStaticState.php', 9],
            'メソッドの中の static 変数' => ['staticState.variable', 'HoldsStaticState.php', 13],
            'クロージャの中の static 変数' => ['staticState.variable', 'HoldsStaticState.php', 26],
            '使われていない trait の static のプロパティ' => ['staticState.property', 'KeepsStaticStateInTrait.php', 8],
            '抑制注釈を付けた static のプロパティ(消せない)' => ['staticState.property', 'SuppressedStaticProperty.php', 11],
        ];
    }

    #[DataProvider('violations')]
    public function test_detects_static_state(string $ruleId, string $file, int $line): void
    {
        $this->assertDetects($ruleId, 'OctaneStaticState/violating/'.$file, $line, self::analyse('OctaneStaticState/violating'));
    }

    public function test_passes_constants_static_methods_and_classes_outside_the_target_namespace(): void
    {
        $this->assertClean(self::analyse('OctaneStaticState/passing'));
    }
}
