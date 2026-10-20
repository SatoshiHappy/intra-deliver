<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * seeder は factory と Faker を使えない(PHPStan の自作規則 ForbiddenExpressionTypesRule。Policy = phpstan.neon の forbiddenExpressionTypes)の自己証明。
 * 本番の初期データは --no-dev で入れるので、require-dev の Faker と、それを使う factory が無い(docs の 設計/コード構成/README.md 2 節)。
 * 呼び出しの結果・受け手・引数の型が factory(Illuminate\Database\Eloquent\Factories\Factory の派生)か Faker\Generator なら、どの書き方でも赤になる
 * (抑制の注釈を付けても消えない)。seeder が業務処理を呼ぶこと・モデルを読むこと、factory の置き場で Faker を使うことは通る。
 *
 * fixture の各ディレクトリ(tests/Architecture/fixtures/Seeders/{violating,passing})をアプリのルートに見立てる。
 */
final class SeedersSelfProofTest extends PhpStanSelfProofTestCase
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function violations(): array
    {
        return [
            'モデルの factory()' => ['database/seeders/UsesFactories.php', 16],
            'factory のクラスの new()' => ['database/seeders/UsesFactories.php', 17],
            'fake()' => ['database/seeders/UsesFactories.php', 18],
            'Faker\Factory::create()' => ['database/seeders/UsesFactories.php', 19],
            '抑制の注釈を付けた factory()(消せない)' => ['database/seeders/UsesFactories.php', 21],
            'run() の引数で受け取る Faker' => ['database/seeders/ReceivesFactories.php', 15],
            'run() の引数で受け取る factory' => ['database/seeders/ReceivesFactories.php', 16],
            '受け取った Faker を使う' => ['database/seeders/ReceivesFactories.php', 18],
            '受け取った factory を使う' => ['database/seeders/ReceivesFactories.php', 19],
        ];
    }

    #[DataProvider('violations')]
    public function test_detects_factories_and_faker_in_a_seeder(string $file, int $line): void
    {
        $this->assertDetects('forbiddenExpressionType', 'Seeders/violating/'.$file, $line, self::analyse('Seeders/violating'));
    }

    public function test_passes_a_seeder_that_calls_actions_and_a_factory_that_uses_faker(): void
    {
        $this->assertClean(self::analyse('Seeders/passing'));
    }
}
