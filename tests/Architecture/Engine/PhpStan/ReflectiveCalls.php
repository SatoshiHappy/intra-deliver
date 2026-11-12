<?php

namespace Tests\Architecture\Engine\PhpStan;

use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;

/**
 * 名前と受け手を字句で決められずに呼べるようにする、リフレクションの生成と、リフレクションと Closure の呼び直し・束ね替え(汎用。
 * {@see AllowedOperationsRule} が厳しい組で使う部品。止めるクラスの名前の頭・型・メソッドは phpstan の設定から受ける)。型の宣言は止めない(宣言だけでは呼べない)。
 */
final readonly class ReflectiveCalls
{
    /** @var list<array{type: string, methods: list<string>|null}> 止める受け手の型と小文字のメソッド名(null = すべてのメソッド) */
    private array $calls;

    /**
     * @param  string  $classPrefix  生成を止める組み込みのクラスの名前の頭(継いだクラスを含む)
     * @param  list<array{type: string, methods: list<string>|null}>  $calls  呼び出しを止める受け手の型(継いだ型を含む)とメソッド(null = すべて)
     */
    public function __construct(
        private string $classPrefix,
        array $calls,
        private ReflectionProvider $reflectionProvider,
    ) {
        $this->calls = array_map(fn (array $call): array => [
            'type' => $call['type'],
            'methods' => $call['methods'] === null ? null : array_map(strtolower(...), $call['methods']),
        ], $calls);
    }

    /**
     * @param  list<OperationReference>  $references  式が指す操作
     * @return list<string>
     */
    public function problemsOf(Expr $node, Scope $scope, array $references): array
    {
        $problems = [];

        if ($node instanceof Expr\New_) {
            foreach ($this->classesOf($scope->getType($node)->getObjectClassNames()) as $class) {
                if ($this->isReflectionClass($class)) {
                    $problems[] = "リフレクションのクラス {$class->getName()} は作れない(名前と受け手を字句で決められずに呼べる)";
                }
            }
        }

        foreach ($references as $reference) {
            array_push($problems, ...$this->callProblems($reference));
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function callProblems(OperationReference $reference): array
    {
        if ($reference->method === null) {
            return [];
        }

        $method = strtolower($reference->method);
        $problems = [];

        foreach ($this->classesOf($reference->classes ?? []) as $class) {
            foreach ($this->calls as $call) {
                $stopped = $call['methods'] === null || in_array($method, $call['methods'], true);

                if ($stopped && AllowedOperationGroup::isA($class, $call['type'])) {
                    $problems[] = "{$class->getName()}::{$reference->method}() は書けない(名前と受け手を字句で決められずに呼べる)";

                    break;
                }
            }
        }

        return $problems;
    }

    private function isReflectionClass(ClassReflection $class): bool
    {
        foreach ($class->getAncestors() as $ancestor) {
            if ($ancestor->isBuiltin() && str_starts_with($ancestor->getName(), $this->classPrefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $names
     * @return list<ClassReflection>
     */
    private function classesOf(array $names): array
    {
        $classes = array_filter($names, $this->reflectionProvider->hasClass(...));

        return array_values(array_map($this->reflectionProvider->getClass(...), $classes));
    }
}
