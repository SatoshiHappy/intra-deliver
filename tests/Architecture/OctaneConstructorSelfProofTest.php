<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Octane 安全 (a)の自己証明。
 * App\ のコンストラクタ引数に container・Request・config リポジトリの型(派生・実装を含む)があれば赤(抑制注釈を付けても消えない)。
 * 実行時の引数・リゾルバのクロージャと、App\ の外のクラスは通る。
 */
final class OctaneConstructorSelfProofTest extends PhpStanSelfProofTestCase
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function violations(): array
    {
        return [
            'Request(昇格プロパティ)' => ['InjectsRequest.php', 11],
            'Request の派生(FormRequest)' => ['InjectsRequest.php', 12],
            'nullable の config リポジトリの契約' => ['InjectsContainerAndConfig.php', 17],
            'union の中の container の契約' => ['InjectsContainerAndConfig.php', 18],
            'intersection の中の PSR の container' => ['InjectsContainerAndConfig.php', 19],
            'container の具象' => ['InjectsContainerAndConfig.php', 20],
            'Application' => ['InjectsContainerAndConfig.php', 21],
            'Application の契約(container の契約の派生)' => ['InjectsContainerAndConfig.php', 22],
            'config リポジトリの具象' => ['InjectsContainerAndConfig.php', 23],
            '抑制注釈を付けた Request(消せない)' => ['SuppressedInjectsRequest.php', 14],
        ];
    }

    #[DataProvider('violations')]
    public function test_detects_a_forbidden_constructor_parameter_type(string $file, int $line): void
    {
        $this->assertDetects('constructor.forbiddenParameterType', 'OctaneConstructor/violating/'.$file, $line, self::analyse('OctaneConstructor/violating'));
    }

    public function test_passes_runtime_arguments_resolvers_and_classes_outside_the_target_namespace(): void
    {
        $this->assertClean(self::analyse('OctaneConstructor/passing'));
    }
}
