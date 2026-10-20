<?php

namespace Tests\Architecture\Engine\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Tests\Architecture\Engine\ComposerNamespaces;

/**
 * 配線のファイル(ルートの下で、クラスを置くディレクトリの外にある PHP のファイル。{@see CodeRoots})が依存してよいのは、
 * 許可した名前空間と外部(禁止した名前空間・クラスを除く)だけ(汎用。値は phpstan の設定から受ける)。
 *
 * 外部 = PHP 標準のクラスと、composer.lock の packages の名前空間(アプリ自身の名前空間と重なるものを除く = {@see ComposerNamespaces})。
 * 依存に数えるのは、ファイルの中のコードすべて(クロージャと匿名クラスを含む)の、クラスを名指す箇所 = new・静的呼び出し・
 * クラス定数・静的プロパティ・instanceof・catch・引数と戻り値とプロパティの型・継承・実装・trait・属性。注釈(PHPDoc)は数えない。
 * 配線のファイルかどうかはパスで決まる(名前空間を宣言しても変わらない)。抑制の注釈では消せない(nonIgnorable)。
 *
 * @implements Rule<FileNode>
 */
final class WiringDependenciesRule implements Rule
{
    private readonly CodeRoots $roots;

    private readonly NamespacePrefixes $allowed;

    private readonly NamespacePrefixes $forbidden;

    private ?NamespacePrefixes $external = null;

    /**
     * @param  list<string>  $roots  アプリのルート(絶対パス)
     * @param  list<string>  $classDirectories  クラスを置くディレクトリ(ルート起点)。この外のファイルが配線のファイル
     * @param  list<string>  $allowedNamespaces  依存してよいアプリの名前空間(その配下すべて)
     * @param  list<string>  $forbiddenNamespaces  外部のうち依存できない名前空間(その配下すべて)
     * @param  list<string>  $forbiddenClasses  外部のうち依存できないクラス
     * @param  string  $composerLock  外部の名前空間を読む composer.lock
     * @param  string  $composerJson  アプリ自身の名前空間を読む composer.json
     */
    public function __construct(
        array $roots,
        array $classDirectories,
        array $allowedNamespaces,
        array $forbiddenNamespaces,
        private readonly array $forbiddenClasses,
        private readonly string $composerLock,
        private readonly string $composerJson,
        private readonly ReflectionProvider $reflectionProvider,
    ) {
        $this->roots = new CodeRoots($roots, $classDirectories);
        $this->allowed = new NamespacePrefixes($allowedNamespaces);
        $this->forbidden = new NamespacePrefixes($forbiddenNamespaces);
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->roots->isWiring($scope->getFile())) {
            return [];
        }

        $errors = [];

        foreach (self::classNames($node->getNodes()) as $name) {
            $class = $this->canonicalName($name->toString());

            if (! $this->isAllowed($class)) {
                $errors[] = $this->error($class, $name->getStartLine());
            }
        }

        return $errors;
    }

    /**
     * クラスの正式な名前(PHP のクラス名は大文字と小文字を区別しないので、書いた綴りでなく宣言の綴りで照合する)。
     * 見つからないクラスは書いた綴りのまま(外部でも許可した名前空間でもなければ指摘になる)。
     */
    private function canonicalName(string $class): string
    {
        return $this->reflectionProvider->hasClass($class) ? $this->reflectionProvider->getClass($class)->getName() : $class;
    }

    private function isAllowed(string $class): bool
    {
        if ($this->forbidden->contains($class) || in_array($class, $this->forbiddenClasses, true)) {
            return false;
        }

        return $this->allowed->contains($class)
            || $this->external()->contains($class)
            || ($this->reflectionProvider->hasClass($class) && $this->reflectionProvider->getClass($class)->isBuiltin());
    }

    private function external(): NamespacePrefixes
    {
        return $this->external ??= new NamespacePrefixes(ComposerNamespaces::fromLock(
            $this->composerLock,
            'packages',
            ComposerNamespaces::fromManifest($this->composerJson),
        ));
    }

    /**
     * クラスを名指す箇所の名前(self・static・parent を除く)。
     *
     * @param  array<Node>  $nodes
     * @return list<Name>
     */
    private static function classNames(array $nodes): array
    {
        $names = [];

        foreach ((new NodeFinder)->find($nodes, fn (Node $node): bool => true) as $node) {
            array_push($names, ...self::referencedNames($node));
        }

        return array_values(array_filter($names, fn (Name $name): bool => ! $name->isSpecialClassName()));
    }

    /**
     * @return list<Name>
     */
    private static function referencedNames(Node $node): array
    {
        return match (true) {
            $node instanceof Expr\New_, $node instanceof Expr\StaticCall, $node instanceof Expr\ClassConstFetch,
            $node instanceof Expr\StaticPropertyFetch, $node instanceof Expr\Instanceof_ => self::names($node->class),
            $node instanceof Stmt\Catch_ => array_values($node->types),
            $node instanceof Node\Param, $node instanceof Stmt\Property => self::names($node->type),
            $node instanceof Node\FunctionLike => self::names($node->getReturnType()),
            $node instanceof Stmt\Class_ => [...self::names($node->extends), ...array_values($node->implements)],
            $node instanceof Stmt\Interface_ => array_values($node->extends),
            $node instanceof Stmt\Enum_ => array_values($node->implements),
            $node instanceof Stmt\TraitUse => array_values($node->traits),
            $node instanceof Node\Attribute => [$node->name],
            default => [],
        };
    }

    /**
     * 型・クラスの位置のノードに含まれる名前(nullable・union・intersection の中を含む)。
     *
     * @return list<Name>
     */
    private static function names(?Node $type): array
    {
        return match (true) {
            $type instanceof Name => [$type],
            $type instanceof Node\NullableType => self::names($type->type),
            $type instanceof Node\UnionType, $type instanceof Node\IntersectionType => array_merge(...array_map(self::names(...), $type->types)),
            default => [],
        };
    }

    private function error(string $class, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message("配線のファイルが {$class} に依存している(依存してよいのは {$this->allowed->describe()} と外部(禁止した名前空間・クラスを除く)だけ)。")
            ->identifier('wiring.dependency')
            ->line($line)
            ->nonIgnorable()
            ->build();
    }
}
