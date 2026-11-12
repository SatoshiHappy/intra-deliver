<?php

namespace Tests\Architecture\Engine\PhpStan;

use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Type;

/**
 * 式が指す操作の一覧(汎用。{@see AllowedOperationsRule} の部品)。
 *
 * 指す形 = インスタンス・nullsafe・静的のメソッド呼び出し(first-class callable を含む)、2 要素の配列 [受け手, 名前](受け手がオブジェクトか
 * class-string で、名前が文字列のもの)、字句の文字列 'クラス::名前'(連結を含む。クラスが在るものだけ)。
 * 受け手は静的な型で決め(self・static・parent は書いた場所のクラス、式で書いたクラスは class-string の対象)、決まらなければ null にする。
 */
final readonly class OperationReferences
{
    /** callable の文字列の形(クラス::メソッド) */
    private const CALLABLE_STRING = '/^\\\\?([A-Za-z_]\\w*(?:\\\\[A-Za-z_]\\w*)*)::([A-Za-z_]\\w*)$/D';

    public function __construct(private ReflectionProvider $reflectionProvider) {}

    /**
     * @return list<OperationReference>
     */
    public function of(Expr $node, Scope $scope): array
    {
        return match (true) {
            $node instanceof Expr\MethodCall, $node instanceof Expr\NullsafeMethodCall => [new OperationReference(
                self::classesOf($scope->getType($node->var)),
                self::nameOf($node->name),
                $node->isFirstClassCallable() ? null : array_values($node->getArgs()),
            )],
            $node instanceof Expr\StaticCall => [new OperationReference(
                self::staticClassesOf($node, $scope),
                self::nameOf($node->name),
                $node->isFirstClassCallable() ? null : array_values($node->getArgs()),
            )],
            $node instanceof Expr\Array_ => self::callableArray($node, $scope),
            $node instanceof String_, $node instanceof Expr\BinaryOp\Concat => $this->callableStrings($node, $scope),
            default => [],
        };
    }

    /**
     * @return list<string>|null
     */
    private static function classesOf(Type $type): ?array
    {
        $classes = $type->getObjectTypeOrClassStringObjectType()->getObjectClassNames();

        return $classes === [] ? null : $classes;
    }

    /**
     * @return list<string>|null
     */
    private static function staticClassesOf(Expr\StaticCall $node, Scope $scope): ?array
    {
        if ($node->class instanceof Name) {
            return [$scope->resolveName($node->class)];
        }

        return self::classesOf($scope->getType($node->class));
    }

    private static function nameOf(Expr|Identifier $name): ?string
    {
        return $name instanceof Identifier ? $name->toString() : null;
    }

    /**
     * [受け手, 名前] の配列。受け手がオブジェクトでも class-string でもない・名前が文字列でない配列は callable でないので指さない。
     *
     * @return list<OperationReference>
     */
    private static function callableArray(Expr\Array_ $node, Scope $scope): array
    {
        $items = $node->items;

        if (count($items) !== 2 || ! self::isPlainItem($items[0]) || ! self::isPlainItem($items[1])) {
            return [];
        }

        $receiver = $scope->getType($items[0]->value);
        $name = $scope->getType($items[1]->value);

        if ($name->isString()->no() || ($receiver->isObject()->no() && $receiver->isClassString()->no())) {
            return [];
        }

        $classes = self::classesOf($receiver);
        $methods = array_map(fn ($string): string => $string->getValue(), $name->getConstantStrings());

        if ($methods === []) {
            return [new OperationReference($classes, null, null)];
        }

        return array_map(fn (string $method) => new OperationReference($classes, $method, null), $methods);
    }

    private static function isPlainItem(ArrayItem $item): bool
    {
        return $item->key === null && ! $item->byRef && ! $item->unpack;
    }

    /**
     * @return list<OperationReference>
     */
    private function callableStrings(String_|Expr\BinaryOp\Concat $node, Scope $scope): array
    {
        $references = [];

        foreach ($scope->getType($node)->getConstantStrings() as $string) {
            $matched = preg_match(self::CALLABLE_STRING, $string->getValue(), $matches) === 1;

            if ($matched && $this->reflectionProvider->hasClass($matches[1])) {
                $references[] = new OperationReference([$matches[1]], $matches[2], null);
            }
        }

        return $references;
    }
}
