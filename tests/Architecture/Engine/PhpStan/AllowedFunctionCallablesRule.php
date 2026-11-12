<?php

namespace Tests\Architecture\Engine\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FunctionCallableNode;
use PHPStan\Rules\Rule;

/**
 * 関数の first-class callable(f(...)・$f(...))を、元の呼び出しで {@see AllowedOperationsRule} に掛ける(汎用。
 * phpstan は first-class callable を仮の節に置き換え、元の呼び出しを規則に渡さないため)。
 *
 * @implements Rule<FunctionCallableNode>
 */
final readonly class AllowedFunctionCallablesRule implements Rule
{
    public function __construct(private AllowedOperationsRule $rule) {}

    public function getNodeType(): string
    {
        return FunctionCallableNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        return $this->rule->processNode($node->getOriginalNode(), $scope);
    }
}
