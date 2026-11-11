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
 * 対象のディレクトリの PHP のファイルは、そのディレクトリの名前空間の下のクラス(interface・trait・enum を含む)を 1 つだけ宣言し、
 * 匿名クラスと、クラスの外の処理(関数・定数・式など)を書かない(汎用。ディレクトリと名前空間の組の一覧は phpstan の設定から受ける)。
 * ファイルは、それを含む最初の組に当てる。
 * クラスの外に書けるのは namespace・use・declare とコメントだけ。抑制の注釈では消せない(nonIgnorable)。
 *
 * @implements Rule<FileNode>
 */
final readonly class SingleClassFileRule implements Rule
{
    private CodeRoots $roots;

    /** @var list<array{directory: string, namespaces: NamespacePrefixes}> */
    private array $groups;

    /**
     * @param  list<string>  $roots  アプリのルート(絶対パス)
     * @param  list<string>  $classDirectories  クラスを置くディレクトリ(ルート起点。{@see CodeRoots})
     * @param  list<array{directory: string, namespace: string}>  $groups  対象のディレクトリ(ルート起点)と、そこにクラスを置ける名前空間(その配下すべて)
     */
    public function __construct(array $roots, array $classDirectories, array $groups)
    {
        $this->roots = new CodeRoots($roots, $classDirectories);
        $this->groups = array_map(
            fn (array $group): array => [
                'directory' => $group['directory'],
                'namespaces' => new NamespacePrefixes([$group['namespace']]),
            ],
            $groups,
        );
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $namespaces = $this->namespacesFor($scope->getFile());

        if ($namespaces === null) {
            return [];
        }

        $statements = TopLevelStatements::of($node->getNodes());
        $classes = array_filter($statements, fn (array $entry): bool => TopLevelStatements::isNamedClassLike($entry[0]));
        $errors = [
            ...self::statementErrors($statements, $namespaces),
            ...self::anonymousClassErrors($node->getNodes()),
        ];

        if (count($classes) !== 1) {
            $errors[] = self::error('クラスの宣言が '.count($classes).' 個ある(1 ファイルに 1 つだけ宣言する)', 'appFile.classCount', 1);
        }

        return $errors;
    }

    /**
     * ファイル $file を含む最初の組の名前空間。どの組の下にも無ければ null。
     */
    private function namespacesFor(string $file): ?NamespacePrefixes
    {
        foreach ($this->groups as $group) {
            if ($this->roots->isUnder($file, [$group['directory']])) {
                return $group['namespaces'];
            }
        }

        return null;
    }

    /**
     * @param  list<array{Stmt, string|null}>  $statements
     * @return list<IdentifierRuleError>
     */
    private static function statementErrors(array $statements, NamespacePrefixes $namespaces): array
    {
        $errors = [];

        foreach ($statements as [$statement, $namespace]) {
            if (! TopLevelStatements::isNamedClassLike($statement)) {
                if (! TopLevelStatements::isDeclarationOnly($statement)) {
                    $errors[] = self::error('クラスの外に処理を書いている(namespace・use・declare とクラスの宣言だけを書く)', 'appFile.codeOutsideClass', $statement->getStartLine());
                }
            } elseif (! $namespaces->contains($namespace)) {
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
