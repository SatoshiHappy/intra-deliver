<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Architecture\Engine\Finding;

/**
 * 公開の口(App\Actions\<M>\Api・App\Queries\<M>\Api の下)の public メソッドの引数・戻り値と public プロパティの型にモデルを使わない
 * (docs の 設計/コード構成/README.md 2 節、検査/README.md 6.2。PHPStan の自作規則 ForbiddenPublicApiTypesRule・ForbiddenPublicApiPropertyTypesRule。
 * Policy = phpstan.neon の forbiddenPublicApiTypes)の自己証明。
 * 引数・戻り値・null を許す型・PHPDoc の配列・総称の型引数・値のクラスのコンストラクタ(昇格した public プロパティ。1 回だけ報告)・昇格でない
 * public プロパティ・配下のサブ名前空間の interface のモデルが赤になる(抑制の注釈を付けても消えない)。識別子・配列・値のクラス・列挙・共有の型・ほかのモジュールの公開の口の型と、口の外・private のメソッドのモデルは通る。
 *
 * fixture の各ディレクトリ(tests/Architecture/fixtures/PublicApi/{violating,passing})を走査ルートにする。
 */
final class PublicApiTypesSelfProofTest extends PhpStanSelfProofTestCase
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function violations(): array
    {
        return [
            '引数のモデル' => ['Actions/Alpha/Api/TakesModels.php', 12],
            '戻り値のモデル' => ['Actions/Alpha/Api/TakesModels.php', 14],
            'null を許す引数のモデル' => ['Actions/Alpha/Api/TakesModels.php', 19],
            'PHPDoc の配列の要素のモデル' => ['Actions/Alpha/Api/TakesModels.php', 27],
            '抑制の注釈を付けた引数のモデル(消せない)' => ['Actions/Alpha/Api/TakesModels.php', 33],
            '値のクラスのコンストラクタの引数のモデル' => ['Actions/Alpha/Api/CarriesModel.php', 12],
            '昇格でない public プロパティのモデル' => ['Actions/Alpha/Api/ExposesModel.php', 12],
            '配下の interface の戻り値の総称の型引数のモデル' => ['Queries/Alpha/Api/Reading/ReturnsModels.php', 16],
        ];
    }

    #[DataProvider('violations')]
    public function test_detects_a_model_in_a_public_api_signature(string $file, int $line): void
    {
        $this->assertDetects('forbiddenPublicApiType', 'PublicApi/violating/'.$file, $line, self::analyse('PublicApi/violating'));
    }

    /**
     * 昇格した public プロパティはコンストラクタの引数としてメソッドの規則だけが見る(プロパティの規則と二重に報告しない)。
     */
    public function test_reports_a_promoted_public_property_once(): void
    {
        $findings = array_filter(
            $this->findings(self::analyse('PublicApi/violating')),
            fn (Finding $finding): bool => str_ends_with($finding->file, 'Actions/Alpha/Api/CarriesModel.php'),
        );

        $this->assertCount(1, $findings);
    }

    public function test_passes_identifiers_arrays_value_classes_and_models_outside_the_public_signature(): void
    {
        $this->assertClean(self::analyse('PublicApi/passing'));
    }
}
