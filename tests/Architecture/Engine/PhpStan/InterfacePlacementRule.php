<?php

namespace Tests\Architecture\Engine\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * 決めた interface を実装するクラスは、決めた名前空間(配下を含む)にだけ置ける(汎用。interface・名前空間・照合しないディレクトリは phpstan の設定から受ける)。
 *
 * 実装 = その interface か、それを継いだ interface を、直接か基底のクラスを通して実装するクラス・列挙・匿名クラス(抽象のクラスを含む。interface は数えない)。
 * 依存の規則は名前空間の中のクラスにだけ掛かるので、外に置いた実装を止めるために置く。照合しないディレクトリと、どのルートの下にも無いファイルは判定しない。
 * 抑制の注釈では消せない(nonIgnorable)。
 *
 * @implements Rule<InClassNode>
 */
final readonly class InterfacePlacementRule implements Rule
{
    private CodeRoots $roots;

    private NamespacePrefixes $namespaces;

    /**
     * @param  list<string>  $roots  アプリのルート(絶対パス)
     * @param  list<string>  $classDirectories  クラスを置くディレクトリ(ルート起点。{@see CodeRoots})
     * @param  list<string>  $exemptDirectories  照合しないディレクトリ(ルート起点)
     * @param  list<string>  $interfaces  置き場を決める interface
     * @param  list<string>  $namespaces  実装を置ける名前空間
     */
    public function __construct(
        array $roots,
        array $classDirectories,
        private array $exemptDirectories,
        private array $interfaces,
        array $namespaces,
    ) {
        $this->roots = new CodeRoots($roots, $classDirectories);
        $this->namespaces = new NamespacePrefixes($namespaces);
    }

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $file = $scope->getFile();
        $class = $node->getClassReflection();

        if ($this->roots->relative($file) === null || $this->roots->isUnder($file, $this->exemptDirectories)) {
            return [];
        }

        if ($class->isInterface() || $this->namespaces->contains($scope->getNamespace())) {
            return [];
        }

        $errors = [];

        foreach ($this->interfaces as $interface) {
            if ($class->getName() !== $interface && $class->implementsInterface($interface)) {
                $errors[] = RuleErrorBuilder::message(
                    "{$interface} を実装するクラスは {$this->namespaces->describe()} の名前空間にだけ置ける: {$class->getDisplayName()}。",
                )
                    ->identifier('interfacePlacement')
                    ->line($node->getStartLine())
                    ->nonIgnorable()
                    ->build();
            }
        }

        return $errors;
    }
}
