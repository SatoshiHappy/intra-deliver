<?php

namespace Tests\Architecture\Engine\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * 対象のディレクトリの PHP のファイルは、対象の名前空間の下のクラス(interface・trait・enum を含む)を 1 つだけ宣言し、
 * 匿名クラスと、クラスの外の処理(関数・定数・式など)を書かない(汎用。ディレクトリと名前空間は phpstan の設定から受ける)。
 * クラスの外に書けるのは namespace・use・declare とコメントだけ。抑制の注釈では消せない(nonIgnorable)。
 *
 * @implements Rule<FileNode>
 */
final readonly class SingleClassFileRule implements Rule
{
    private CodeRoots $roots;

    private NamespacePrefixes $namespaces;

    /**
     * @param  list<string>  $roots  アプリのルート(絶対パス)
     * @param  list<string>  $classDirectories  クラスを置くディレクトリ(ルート起点。{@see CodeRoots})
     * @param  string  $directory  対象のディレクトリ(ルート起点)
     * @param  string  $namespace  クラスを置ける名前空間(その配下すべて)
     */
    public function __construct(array $roots, array $classDirectories, private string $directory, string $namespace)
    {
        $this->roots = new CodeRoots($roots, $classDirectories);
        $this->namespaces = new NamespacePrefixes([$namespace]);
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->roots->isUnder($scope->getFile(), [$this->directory])) {
            return [];
        }

        $statements = TopLevelStatements::of($node->getNodes());
        $classes = array_filter($statements, fn (array $entry): bool => TopLevelStatements::isNamedClassLike($entry[0]));
        $errors = [...$this->statementErrors($statements), ...self::anonymousClassErrors($node->getNodes())];

        if (count($classes) !== 1) {
            $errors[] = self::error('クラスの宣言が '.count($classes).' 個ある(1 ファイルに 1 つだけ宣言する)', 'appFile.classCount', 1);
        }

        return $errors;
    }

    /**
     * @param  list<array{Stmt, string|null}>  $statements
     * @return list<IdentifierRuleError>
     */
    private function statementErrors(array $statements): array
    {
        $errors = [];

        foreach ($statements as [$statement, $namespace]) {
            if (! TopLevelStatements::isNamedClassLike($statement)) {
                if (! TopLevelStatements::isDeclarationOnly($statement)) {
                    $errors[] = self::error('クラスの外に処理を書いている(namespace・use・declare とクラスの宣言だけを書く)', 'appFile.codeOutsideClass', $statement->getStartLine());
                }
            } elseif (! $this->namespaces->contains($namespace)) {
                $errors[] = self::error('クラスを '.($namespace ?? '名前空間の外').' に宣言している(置ける名前空間の下に置く)', 'appFile.namespace', $statement->getStartLine());
            }
        }

        return $errors;
    }

    /**
     * @param  array<Node>  $nodes
     * @return list<IdentifierRuleError>
     */
    private static function anonymousClassErrors(array $nodes): array
    {
        $anonymous = array_filter((new NodeFinder)->findInstanceOf($nodes, Class_::class), fn (Class_ $class): bool => $class->name === null);

        return array_values(array_map(
            fn (Class_ $class): IdentifierRuleError => self::error('匿名クラスを書いている(名前空間で層とモジュールに属さない)', 'appFile.anonymousClass', $class->getStartLine()),
            $anonymous,
        ));
    }

    private static function error(string $message, string $identifier, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message.'。')
            ->identifier($identifier)
            ->line($line)
            ->nonIgnorable()
            ->build();
    }
}
