<?php

namespace Tests\Architecture\Engine\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;

/**
 * 公開の口(層の名前空間の下の、モジュールの次の段が決めた名前の名前空間。例 = App\Actions\<M>\Api)のクラス・interface の public メソッド
 * (コンストラクタを含む = 昇格した public プロパティはここで見る)の引数・戻り値の型に、禁じた型(とその派生)を使わない(汎用。層・段の名前・型は
 * phpstan の設定から受ける)。昇格でない public プロパティは {@see ForbiddenPublicApiPropertyTypesRule} が見る(判定は {@see PublicApiTypes} で共有)。
 * 抑制の注釈では消せない(nonIgnorable)。
 *
 * 型は PHPDoc を含めて解決した型で見て、配列・総称の型引数・union の中に現れるクラスも数える(例 = list<Record>・Collection<int, Record>・?Record)。
 * private・protected のメソッドと、メソッドの中で使う型は見ない(口の内側の実装は止めない)。
 *
 * @implements Rule<InClassMethodNode>
 */
final readonly class ForbiddenPublicApiTypesRule implements Rule
{
    private PublicApiTypes $publicApi;

    /**
     * @param  list<string>  $layers  公開の口を持つ層の名前空間(例 = App\Actions)。口の名前空間は <層>\<モジュール>\<段の名前> とその配下
     * @param  string  $segment  公開の口の段の名前(例 = Api)
     * @param  list<class-string>  $types  禁じる型(クラス・interface。派生を含む)
     */
    public function __construct(array $layers, string $segment, array $types)
    {
        $this->publicApi = new PublicApiTypes($layers, $segment, $types);
    }

    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $method = $node->getMethodReflection();

        if (! $method->isPublic() || ! $this->publicApi->contains($node->getClassReflection()->getName())) {
            return [];
        }

        $errors = [];

        foreach ($method->getVariants() as $variant) {
            foreach ($variant->getParameters() as $parameter) {
                $errors = [...$errors, ...$this->errorsFor($parameter->getType(), "引数 \${$parameter->getName()} ", $node)];
            }

            $errors = [...$errors, ...$this->errorsFor($variant->getReturnType(), '戻り値', $node)];
        }

        return $errors;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function errorsFor(Type $type, string $place, InClassMethodNode $node): array
    {
        $forbidden = $this->publicApi->forbiddenIn($type);

        return $forbidden === null ? [] : [
            RuleErrorBuilder::message("公開の口の public メソッド {$node->getMethodReflection()->getName()}() の{$place}に {$forbidden} を使えない(型 = {$type->describe(VerbosityLevel::typeOnly())})。")
                ->identifier('forbiddenPublicApiType')
                ->line($node->getStartLine())
                ->nonIgnorable()
                ->build(),
        ];
    }
}
