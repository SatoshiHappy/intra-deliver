<?php

namespace Tests\Architecture\Engine\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * クラスとメソッドの物理行数の上限(汎用。上限の値は phpstan の設定から受け、この規則は値を持たない)。
 *
 * 行数 = 宣言の開始行(属性があれば属性の行)から終了行までの物理行で、本体の中のコメントと空行を含む。宣言の前の docblock は含まない。
 * クラス = class・interface・trait・enum(無名クラスを含む)。メソッド = クラスのメソッドと関数(クロージャは囲むメソッドの行に入る)。
 * 構文だけで判定するので、使われていない trait も対象になる。抑制注釈では消せない(nonIgnorable)。
 *
 * @implements Rule<FileNode>
 */
final readonly class PhysicalLinesRule implements Rule
{
    public function __construct(
        private int $maxClassLines,
        private int $maxMethodLines,
    ) {}

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $finder = new NodeFinder;
        $errors = [];

        foreach ($finder->findInstanceOf($node->getNodes(), ClassLike::class) as $classLike) {
            $name = $classLike->namespacedName ?? $classLike->name;
            $label = 'クラス '.($name?->toString() ?? '(無名クラス)');
            $errors = [...$errors, ...self::check($classLike, $label, $this->maxClassLines, 'physicalLines.class')];
        }

        foreach ($finder->findInstanceOf($node->getNodes(), ClassMethod::class) as $method) {
            $label = 'メソッド '.$method->name->toString().'()';
            $errors = [...$errors, ...self::check($method, $label, $this->maxMethodLines, 'physicalLines.method')];
        }

        foreach ($finder->findInstanceOf($node->getNodes(), Function_::class) as $function) {
            $label = '関数 '.($function->namespacedName ?? $function->name)->toString().'()';
            $errors = [...$errors, ...self::check($function, $label, $this->maxMethodLines, 'physicalLines.method')];
        }

        return $errors;
    }

    /**
     * 宣言 $declaration の物理行数が上限 $limit を超えていれば、宣言の開始行に 1 件の指摘を返す。
     *
     * @return list<IdentifierRuleError>
     */
    private static function check(Node $declaration, string $label, int $limit, string $identifier): array
    {
        $lines = $declaration->getEndLine() - $declaration->getStartLine() + 1;

        if ($lines <= $limit) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf('%s の物理行数 %d が上限 %d を超える(コメント・空行を含む)。', $label, $lines, $limit))
                ->identifier($identifier)
                ->line($declaration->getStartLine())
                ->nonIgnorable()
                ->build(),
        ];
    }
}
