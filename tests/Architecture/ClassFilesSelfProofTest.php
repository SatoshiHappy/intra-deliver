<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * B1 ファイルの形の規則の自己証明(docs の 設計/コード構成/README.md 1 節・2 節。PHPStan の自作規則)。
 * - app/ のファイルは App\ の名前空間のクラスを 1 つだけ宣言し、匿名クラスとクラスの外の処理を書かない(SingleClassFileRule)
 * - database/ のファイルはクラスの外に処理を書かない。マイグレーションだけ return new class を書ける(ClassOnlyFileRule)
 *
 * fixture の各ディレクトリ(tests/Architecture/fixtures/ClassFiles/{violating,passing})をアプリのルートに見立てる
 * (fixture 用の設定 tests/Architecture/fixtures/phpstan.neon がルートを差し替える)。
 */
final class ClassFilesSelfProofTest extends PhpStanSelfProofTestCase
{
    /**
     * @return array<string, array{string, string, int}>
     */
    public static function violations(): array
    {
        return [
            'app/ に 2 つのクラス' => ['appFile.classCount', 'app/Support/TwoClasses.php', 1],
            'app/ にクラスが無い' => ['appFile.classCount', 'app/Support/FunctionOnly.php', 1],
            'app/ の関数' => ['appFile.codeOutsideClass', 'app/Support/FunctionOnly.php', 5],
            'app/ のクラスの後の定数' => ['appFile.codeOutsideClass', 'app/Support/CodeAfterClass.php', 12],
            'app/ の App\ の外の名前空間' => ['appFile.namespace', 'app/Support/OutsideNamespace.php', 8],
            'app/ の名前空間の無いクラス' => ['appFile.namespace', 'app/Support/GlobalClass.php', 6],
            'app/ の匿名クラス' => ['appFile.anonymousClass', 'app/Support/MakesAnonymousClass.php', 12],
            'app/ のブロックつきの declare の中の処理' => ['appFile.codeOutsideClass', 'app/Support/DeclareBlock.php', 5],
            '抑制の注釈を付けたクラスの外の定数(消せない)' => ['appFile.codeOutsideClass', 'app/Support/SuppressedCodeAfterClass.php', 13],
            'database/ のクラスの後の処理' => ['classOnlyFile.codeOutsideClass', 'database/seeders/CodeAfterClass.php', 12],
            'マイグレーションでない場所の return new class' => ['classOnlyFile.codeOutsideClass', 'database/factories/ReturnsAnonymousClass.php', 6],
            'マイグレーションの return new class の前の処理' => ['classOnlyFile.codeOutsideClass', 'database/migrations/2026_01_01_000000_code_before_class.php', 5],
        ];
    }

    #[DataProvider('violations')]
    public function test_detects_a_file_that_is_not_a_single_class(string $ruleId, string $file, int $line): void
    {
        $this->assertDetects($ruleId, 'ClassFiles/violating/'.$file, $line, self::analyse('ClassFiles/violating'));
    }

    public function test_passes_files_with_a_single_class(): void
    {
        $this->assertClean(self::analyse('ClassFiles/passing'));
    }
}
