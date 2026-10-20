<?php

namespace Tests\Architecture\Engine\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Static_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * 対象の名前空間に static の可変状態(static のプロパティと、関数・メソッド・クロージャの中の static 変数)が無いこと
 * (汎用。対象の名前空間は phpstan の設定から受ける)。
 *
 * 定数(const)・static メソッド・static クロージャ・static の戻り型と遅延静的束縛は状態ではないので対象外。
 * 構文だけで判定するので、使われていない trait も対象になる。抑制注釈では消せない(nonIgnorable)。
 *
 * @implements Rule<FileNode>
 */
final readonly class ForbiddenStaticStateRule implements Rule
{
    private NamespacePrefixes $namespaces;

    /**
     * @param  list<string>  $namespaces  対象の名前空間(その配下すべて)
     */
    public function __construct(array $namespaces)
    {
        $this->namespaces = new NamespacePrefixes($namespaces);
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $finder = new NodeFinder;
        $errors = [];

        foreach ($finder->findInstanceOf($node->getNodes(), Namespace_::class) as $namespace) {
            if ($this->namespaces->contains($namespace->name?->toString())) {
                $errors = [...$errors, ...self::check($finder, $namespace)];
            }
        }

        return $errors;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private static function check(NodeFinder $finder, Namespace_ $namespace): array
    {
        $errors = [];

        foreach ($finder->findInstanceOf($namespace->stmts, Property::class) as $property) {
            if ($property->isStatic()) {
                $names = array_map(fn (Node\PropertyItem $item): string => '$'.$item->name->toString(), $property->props);
                $errors[] = self::error('static のプロパティ '.implode(', ', $names), 'staticState.property', $property->getStartLine());
            }
        }

        foreach ($finder->findInstanceOf($namespace->stmts, Static_::class) as $static) {
            $names = array_map(fn (Node\StaticVar $item): string => is_string($item->var->name) ? '$'.$item->var->name : '(動的な名前)', $static->vars);
            $errors[] = self::error('関数の中の static 変数 '.implode(', ', $names), 'staticState.variable', $static->getStartLine());
        }

        return $errors;
    }

    private static function error(string $subject, string $identifier, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message($subject.' を持たない(要求をまたいで状態が残る。定数・要求ごとのオブジェクト・実行時の引数に置く)。')
            ->identifier($identifier)
            ->line($line)
            ->nonIgnorable()
            ->build();
    }
}
