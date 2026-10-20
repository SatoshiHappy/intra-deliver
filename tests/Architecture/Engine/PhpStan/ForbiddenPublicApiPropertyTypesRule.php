<?php

namespace Tests\Architecture\Engine\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\ClassPropertyNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\VerbosityLevel;

/**
 * 公開の口(例 = App\Actions\<M>\Api)のクラスの public プロパティ(昇格でないもの)の型に、禁じた型(とその派生)を使わない(汎用。設定と判定は
 * {@see ForbiddenPublicApiTypesRule} と同じ = {@see PublicApiTypes})。昇格した public プロパティはコンストラクタの引数としてメソッドの規則が見るので、
 * ここでは見ない(二重に報告しない)。型は PHPDoc の型と宣言の型の両方を見る。識別子はメソッドの規則と同じ。抑制の注釈では消せない(nonIgnorable)。
 *
 * @implements Rule<ClassPropertyNode>
 */
final readonly class ForbiddenPublicApiPropertyTypesRule implements Rule
{
    private PublicApiTypes $publicApi;

    /**
     * @param  list<string>  $layers  公開の口を持つ層の名前空間(例 = App\Actions)
     * @param  string  $segment  公開の口の段の名前(例 = Api)
     * @param  list<class-string>  $types  禁じる型(クラス・interface。派生を含む)
     */
    public function __construct(array $layers, string $segment, array $types)
    {
        $this->publicApi = new PublicApiTypes($layers, $segment, $types);
    }

    public function getNodeType(): string
    {
        return ClassPropertyNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->isPublic() || $node->isPromoted() || ! $this->publicApi->contains($node->getClassReflection()->getName())) {
            return [];
        }

        foreach (array_filter([$node->getPhpDocType(), $node->getNativeType()]) as $type) {
            $forbidden = $this->publicApi->forbiddenIn($type);

            if ($forbidden !== null) {
                return [
                    RuleErrorBuilder::message("公開の口の public プロパティ \${$node->getName()} に {$forbidden} を使えない(型 = {$type->describe(VerbosityLevel::typeOnly())})。")
                        ->identifier('forbiddenPublicApiType')
                        ->line($node->getStartLine())
                        ->nonIgnorable()
                        ->build(),
                ];
            }
        }

        return [];
    }
}
