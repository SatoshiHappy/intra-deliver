<?php

namespace Tests\Architecture\Engine\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;

/**
 * 対象のディレクトリで、禁じた型(とその派生)の値を作る・受け取る・使うコードを止める(汎用。ディレクトリと型は phpstan の設定から受ける)。
 * 抑制の注釈では消せない(nonIgnorable)。
 *
 * 見るのは静的な型だけで、どの名前で書いたかは見ない:
 * - 呼び出し(関数・メソッド・静的メソッド・new)の結果の型(例: Model::factory()・fake())
 * - メソッド呼び出しの受け手と、静的呼び出しのクラスの型(例: 引数で受け取った factory の ->create())
 * - 関数・メソッドの引数の型(例: seeder の run() の引数。Laravel はコンテナで解決して渡すので、受け取るだけで実体が要る)
 * 型が静的に決まらないもの(mixed)は止まらない。
 *
 * @implements Rule<Node>
 */
final readonly class ForbiddenExpressionTypesRule implements Rule
{
    private CodeRoots $roots;

    /**
     * @param  list<string>  $roots  アプリのルート(絶対パス)
     * @param  list<string>  $classDirectories  クラスを置くディレクトリ(ルート起点。{@see CodeRoots})
     * @param  list<array{directories: list<string>, types: list<class-string>}>  $groups  組ごとの、対象のディレクトリ(ルート起点)と禁じる型(クラス・interface。派生を含む)
     */
    public function __construct(array $roots, array $classDirectories, private array $groups)
    {
        $this->roots = new CodeRoots($roots, $classDirectories);
    }

    public function getNodeType(): string
    {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $types = match (true) {
            $node instanceof CallLike => self::callTypes($node, $scope),
            $node instanceof Param && $node->type !== null => [$scope->getFunctionType($node->type, false, false)],
            default => [],
        };

        return $types === [] ? [] : $this->errorsFor($types, $node, $scope->getFile());
    }

    /**
     * @param  list<Type>  $types
     * @return list<IdentifierRuleError>
     */
    private function errorsFor(array $types, Node $node, string $file): array
    {
        $groups = array_filter($this->groups, fn (array $group): bool => $this->roots->isUnder($file, $group['directories']));

        foreach ($groups as $group) {
            foreach ($types as $type) {
                $error = self::errorFor($type, $group['types'], $node);

                if ($error !== null) {
                    return [$error];
                }
            }
        }

        return [];
    }

    /**
     * 呼び出しの結果の型と、受け手(メソッド呼び出しの式・静的呼び出しのクラス)の型。
     *
     * @return list<Type>
     */
    private static function callTypes(CallLike $node, Scope $scope): array
    {
        $types = [$scope->getType($node)];

        if ($node instanceof MethodCall || $node instanceof NullsafeMethodCall) {
            $types[] = $scope->getType($node->var);
        } elseif ($node instanceof StaticCall) {
            $types[] = $node->class instanceof Name ? $scope->resolveTypeByName($node->class) : $scope->getType($node->class);
        }

        return $types;
    }

    /**
     * @param  list<class-string>  $forbiddenTypes
     */
    private static function errorFor(Type $type, array $forbiddenTypes, Node $node): ?IdentifierRuleError
    {
        foreach ($forbiddenTypes as $forbidden) {
            if ((new ObjectType($forbidden))->isSuperTypeOf($type)->yes()) {
                return RuleErrorBuilder::message("この場所では {$forbidden} を作る・受け取る・使うコードを書けない(型 = {$type->describe(VerbosityLevel::typeOnly())})。")
                    ->identifier('forbiddenExpressionType')
                    ->line($node->getStartLine())
                    ->nonIgnorable()
                    ->build();
            }
        }

        return null;
    }
}
