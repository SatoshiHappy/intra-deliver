<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * B1 配線のファイルの依存先(docs の 設計/コード構成/README.md 2 節。PHPStan の自作規則 WiringDependenciesRule)の自己証明。
 * 配線のファイル(routes・bootstrap・config・public など、クラスを置くディレクトリの外)が依存してよいのは入口・共有・起動・コマンド・外部(DB を除く)だけ
 * (コマンドはスケジューラの定義が名指す)。
 * クロージャと匿名クラスの中も、名前空間を宣言したファイルも対象になる。
 *
 * fixture の各ディレクトリ(tests/Architecture/fixtures/Wiring/{violating,passing})をアプリのルートに見立てる。
 */
final class WiringSelfProofTest extends PhpStanSelfProofTestCase
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function violations(): array
    {
        return [
            'routes のクロージャから永続化' => ['routes/web.php', 6],
            'routes から DB のファサード' => ['routes/console.php', 5],
            '名前空間を宣言した配線のファイルから永続化' => ['routes/namespaced.php', 7],
            'config のクラス名の登録で永続化' => ['config/auth.php', 4],
            'config の匿名クラスの継承で DB' => ['config/services.php', 6],
            'bootstrap のクロージャの引数の型で DB' => ['bootstrap/app.php', 5],
            'public から composer.lock に無い名前空間' => ['public/index.php', 5],
            '抑制の注釈を付けた依存(消せない)' => ['routes/suppressed.php', 6],
            '小文字で書いた PDO(クラス名は大文字と小文字を区別しない)' => ['routes/pdo.php', 3],
            'PDO のドライバごとの派生' => ['routes/pdo.php', 4],
            '配線のファイルからジョブ(コマンドのほかの層)' => ['routes/jobs.php', 5],
        ];
    }

    #[DataProvider('violations')]
    public function test_detects_a_dependency_outside_the_allowed_targets(string $file, int $line): void
    {
        $this->assertDetects('wiring.dependency', 'Wiring/violating/'.$file, $line, self::analyse('Wiring/violating'));
    }

    public function test_passes_dependencies_on_entrance_support_providers_commands_and_external(): void
    {
        $this->assertClean(self::analyse('Wiring/passing'));
    }
}
