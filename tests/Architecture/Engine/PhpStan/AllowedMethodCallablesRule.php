<?php

namespace Tests\Architecture\Engine\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\MethodCallableNode;
use PHPStan\Rules\Rule;

/**
 * インスタンスのメソッドの first-class callable($x->m(...))を、元の呼び出しで {@see AllowedOperationsRule} に掛ける(汎用。
 * phpstan は first-class callable を仮の節に置き換え、元の呼び出しを規則に渡さないため)。
 *
 * @implements Rule<MethodCallableNode>
 */
final readonly class AllowedMethodCallablesRule implements Rule
{
    public function __construct(private AllowedOperationsRule $rule) {}

    public function getNodeType(): string
    {
        return MethodCallableNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        return $this->rule->processNode($node->getOriginalNode(), $scope);
    }
}
