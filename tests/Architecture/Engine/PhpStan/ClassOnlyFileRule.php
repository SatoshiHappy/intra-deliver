<?php

namespace Tests\Architecture\Engine\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * 対象のディレクトリの PHP のファイルは、クラスの外に処理を書かない(汎用。ディレクトリは phpstan の設定から受ける)。
 * クラスの外に書けるのは namespace・use・declare・コメントとクラスの宣言と、匿名クラスを返す文を書けるディレクトリ
 * (Laravel のマイグレーション)では `return new class …;` の 1 文。抑制の注釈では消せない(nonIgnorable)。
 *
 * @implements Rule<FileNode>
 */
final readonly class ClassOnlyFileRule implements Rule
{
    private CodeRoots $roots;

    /**
     * @param  list<string>  $roots  アプリのルート(絶対パス)
     * @param  list<string>  $classDirectories  クラスを置くディレクトリ(ルート起点。{@see CodeRoots})
     * @param  list<string>  $directories  対象のディレクトリ(ルート起点)
     * @param  list<string>  $returnClassDirectories  `return new class …;` を書けるディレクトリ(ルート起点)
     */
    public function __construct(array $roots, array $classDirectories, private array $directories, private array $returnClassDirectories)
    {
        $this->roots = new CodeRoots($roots, $classDirectories);
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->roots->isUnder($scope->getFile(), $this->directories)) {
            return [];
        }

        $returnsClass = $this->roots->isUnder($scope->getFile(), $this->returnClassDirectories);
        $errors = [];

        foreach (TopLevelStatements::of($node->getNodes()) as [$statement]) {
            if (TopLevelStatements::isDeclarationOnly($statement) || TopLevelStatements::isNamedClassLike($statement)) {
                continue;
            }

            if ($returnsClass && self::returnsAnonymousClass($statement)) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message('クラスの外に処理を書いている(namespace・use・declare とクラスの宣言'.($returnsClass ? '・return new class …;' : '').' だけを書く)。')
                ->identifier('classOnlyFile.codeOutsideClass')
                ->line($statement->getStartLine())
                ->nonIgnorable()
                ->build();
        }

        return $errors;
    }

    private static function returnsAnonymousClass(Stmt $statement): bool
    {
        return $statement instanceof Stmt\Return_
            && $statement->expr instanceof New_
            && $statement->expr->class instanceof Class_;
    }
}
