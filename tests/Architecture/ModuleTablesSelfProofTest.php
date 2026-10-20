<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 機械検査 (d) 自分のモジュールの表は自分の業務処理だけが書く(docs の 設計/業務ツール/機械検査.md。PHPStan の自作規則 ModuleTableWritesRule、
 * Policy = tests/Architecture/Policy/ModuleTables.php)の自己証明。ほかのモジュールの業務処理・名前が同じ文字で始まる別のモジュール・同じモジュールの
 * 業務処理の外から App\Models\<M> を書くと、書き方(インスタンス・ビルダ・静的呼び出し・リレーション・nullsafe)に依らず赤になる(抑制の注釈を付けても消えない)。
 * 書き方には和の型の受け手・class-string の静的呼び出しを含む。持ち主の業務処理(配下を含む)の書き込み・ほかのモジュールの表の読み取り・関連先が自分の表の
 * リレーションの書き込み・置き場の直下のモデル(モジュールが分からない)・照合しないディレクトリ(database/)は通る。
 *
 * fixture の各ディレクトリ(tests/Architecture/fixtures/ModuleTables/{violating,passing})をアプリのルートに見立てる。
 */
final class ModuleTablesSelfProofTest extends PhpStanSelfProofTestCase
{
    private const RULE = 'moduleTableWrite';

    /**
     * @return array<string, array{string, int}>
     */
    public static function violations(): array
    {
        return [
            'ほかのモジュールのモデルの保存' => ['app/Actions/Beta/WritesAlpha.php', 15],
            'ほかのモジュールのビルダの更新' => ['app/Actions/Beta/WritesAlpha.php', 16],
            'ほかのモジュールのモデルの静的呼び出しで作成' => ['app/Actions/Beta/WritesAlpha.php', 17],
            '自分のモデルのリレーションから、ほかのモジュールの表を作成' => ['app/Actions/Beta/WritesAlpha.php', 18],
            'ほかのモジュールのモデルの nullsafe の削除' => ['app/Actions/Beta/WritesAlpha.php', 19],
            '抑制の注釈を付けたほかのモジュールのモデルの削除(消せない)' => ['app/Actions/Beta/WritesAlpha.php', 21],
            'ビルダとリレーションの和の受け手の削除' => ['app/Actions/Beta/WritesAlphaThroughTypes.php', 19],
            'class-string の変数の静的呼び出しで作成' => ['app/Actions/Beta/WritesAlphaThroughTypes.php', 20],
            'null を含む型の変数の nullsafe の削除' => ['app/Actions/Beta/WritesAlphaThroughTypes.php', 22],
            '名前が同じ文字で始まる別のモジュール' => ['app/Actions/AlphaExtra/WritesAlpha.php', 14],
            '同じモジュールの業務処理の外(読み取り)' => ['app/Queries/Alpha/WritesOwnModel.php', 14],
        ];
    }

    #[DataProvider('violations')]
    public function test_detects_a_write_to_a_table_of_another_module(string $file, int $line): void
    {
        $this->assertDetects(self::RULE, 'ModuleTables/violating/'.$file, $line, self::analyse('ModuleTables/violating'));
    }

    public function test_passes_writes_by_the_owner_and_reads_by_others(): void
    {
        $this->assertClean(self::analyse('ModuleTables/passing'));
    }
}
