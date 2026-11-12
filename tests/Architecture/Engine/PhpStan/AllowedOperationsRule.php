<?php

namespace Tests\Architecture\Engine\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * 決めた名前空間から決めた型に届く操作は、許す一覧の操作だけ(汎用。掛ける名前空間・受け手の型・許す操作・鍵の引数は phpstan の設定から組ごとに受ける)。
 *
 * 届く操作 = 受け手の型(継いだ型を含む)の、インスタンス・nullsafe・静的の呼び出しと first-class callable・callable の配列と文字列({@see OperationReferences})。
 * 許した操作の鍵の引数は、無いか null の字句であること(callable として指すだけの形は渡す引数を決められないので違反)。
 * 厳しい組({@see AllowedOperationGroup::isStrict()})では、受け手の型によらず、名前が字句でない呼び出し・受け手の型が決まらない呼び出し・
 * 引数の展開・字句でない callable を受ける関数とメソッド(設定の一覧。コンテナの call など)・Closure でない値の呼び出しも違反にする({@see LooseCalls})。
 * first-class callable は phpstan が仮の節に置き換えて元の呼び出しを規則に渡さないので、仮の節ごとの規則
 * ({@see AllowedMethodCallablesRule}・{@see AllowedStaticMethodCallablesRule}・{@see AllowedFunctionCallablesRule})が
 * 元の呼び出しでこの規則を呼ぶ。抑制の注釈では消せない(nonIgnorable)。
 *
 * @implements Rule<Expr>
 */
final readonly class AllowedOperationsRule implements Rule
{
    private const IDENTIFIER = 'allowedOperation';

    /** リフレクションと Closure の束ね替え({@see ReflectiveCalls})の識別子 */
    private const REFLECTIVE_IDENTIFIER = 'reflectiveCall';

    /** @var list<AllowedOperationGroup> */
    private array $groups;

    private OperationReferences $references;

    private LooseCalls $looseCalls;

    private ReflectiveCalls $reflectiveCalls;

    /**
     * @param  list<array{namespaces: list<string>, receiverNamespaces: list<string>, receiverClasses: list<string>,
     *     operations: list<array{class: string, methods: list<string>}>,
     *     keyArguments: list<array{class: string, method: string, parameter: string, position: int}>,
     *     strict: bool}>  $groups  規則の組
     * @param  list<string>  $callableFunctions  字句でない callable を受ける関数(厳しい組で止める)
     * @param  list<array{class: string, methods: list<string>}>  $callableMethods  字句でない callable を受けるメソッド(厳しい組で止める)
     * @param  array{classPrefix: string,
     *     calls: list<array{type: string, methods: list<string>|null}>}  $reflective  止めるリフレクションと束ね替え
     */
    public function __construct(
        array $groups,
        array $callableFunctions,
        private array $callableMethods,
        array $reflective,
        private ReflectionProvider $reflectionProvider,
    ) {
        $this->reflectiveCalls = new ReflectiveCalls(
            $reflective['classPrefix'],
            $reflective['calls'],
            $reflectionProvider,
        );
        $this->groups = array_map(fn (array $group) => new AllowedOperationGroup($group), $groups);
        $this->references = new OperationReferences($reflectionProvider);
        $this->looseCalls = new LooseCalls($callableFunctions);
    }

    public function getNodeType(): string
    {
        return Expr::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];

        foreach ($this->groups as $group) {
            if (! $group->covers($scope->getNamespace())) {
                continue;
            }

            $references = $this->references->of($node, $scope);

            if ($group->isStrict()) {
                array_push($errors, ...$this->strictErrors($group, $node, $scope, $references));
            }

            foreach ($references as $reference) {
                array_push($errors, ...$this->referenceErrors($group, $reference, $node));
            }
        }

        return $errors;
    }

    /**
     * 厳しい組だけで止める、受け手によらない形。
     *
     * @param  list<OperationReference>  $references
     * @return list<IdentifierRuleError>
     */
    private function strictErrors(AllowedOperationGroup $group, Expr $node, Scope $scope, array $references): array
    {
        $errors = [];

        foreach ($this->looseCalls->problemsOf($node, $scope) as $problem) {
            $errors[] = $this->error($group, $problem, $node);
        }

        foreach ($this->reflectiveCalls->problemsOf($node, $scope, $references) as $problem) {
            $errors[] = $this->error($group, $problem, $node, self::REFLECTIVE_IDENTIFIER);
        }

        return $errors;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function referenceErrors(AllowedOperationGroup $group, OperationReference $reference, Expr $node): array
    {
        if ($reference->classes === null) {
            return $group->isStrict() ? [$this->error($group, '受け手の型が決まらない呼び出しは書けない(受け手に型を付ける)', $node)] : [];
        }

        $errors = [];

        foreach ($reference->classes as $name) {
            $problem = $this->classProblem($group, $name, $reference);

            if ($problem !== null) {
                $errors[] = $this->error($group, $problem, $node);
            }
        }

        return $errors;
    }

    private function classProblem(AllowedOperationGroup $group, string $name, OperationReference $reference): ?string
    {
        if (! $this->reflectionProvider->hasClass($name)) {
            return $group->isStrict() ? "受け手の型 {$name} が決まらない(受け手に型を付ける)" : null;
        }

        return $this->problemOf($group, $this->reflectionProvider->getClass($name), $reference);
    }

    private function problemOf(
        AllowedOperationGroup $group,
        ClassReflection $class,
        OperationReference $reference,
    ): ?string {
        $name = $class->getName();

        if ($reference->method === null) {
            return $group->isStrict() || $group->reaches($class) ? "{$name} の操作の名前が字句でない(届く操作を決められない)" : null;
        }

        $operation = "{$name}::{$reference->method}()";

        if ($group->isStrict() && $this->takesCallable($class, $reference->method)) {
            return "字句でない callable を受ける {$operation} は書けない(届く操作と鍵の引数を決められない)";
        }

        if (! $group->reaches($class)) {
            return null;
        }

        if (! $group->allows($class, $reference->method)) {
            return "{$operation} は許す操作の一覧に無い";
        }

        $keys = $group->keyArgumentsOf($class, $reference->method);

        return $this->keyArgumentProblem($keys, $reference->arguments, $operation);
    }

    private function takesCallable(ClassReflection $class, string $method): bool
    {
        foreach ($this->callableMethods as $callable) {
            $methods = array_map(strtolower(...), $callable['methods']);

            $isCallable = in_array(strtolower($method), $methods, true);

            if ($isCallable && AllowedOperationGroup::isA($class, $callable['class'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{parameter: string, position: int}>  $keys
     * @param  list<Arg>|null  $arguments
     */
    private function keyArgumentProblem(array $keys, ?array $arguments, string $operation): ?string
    {
        foreach ($keys as $key) {
            if ($arguments === null) {
                return "{$operation} は鍵の引数 \${$key['parameter']} を決められない形(callable)で使えない";
            }

            $argument = self::argumentOf($arguments, $key['parameter'], $key['position']);

            if ($argument !== null && ! self::isNullLiteral($argument->value)) {
                return "{$operation} の鍵の引数 \${$key['parameter']} は省くか null の字句にする";
            }
        }

        return null;
    }

    /**
     * 名前つきの引数 $parameter か、位置 $position(0 起点)の位置の引数。
     *
     * @param  list<Arg>  $arguments
     */
    private static function argumentOf(array $arguments, string $parameter, int $position): ?Arg
    {
        foreach ($arguments as $index => $argument) {
            if ($argument->name !== null ? $argument->name->toString() === $parameter : $index === $position) {
                return $argument;
            }
        }

        return null;
    }

    private static function isNullLiteral(Expr $value): bool
    {
        return $value instanceof Expr\ConstFetch && $value->name->toLowerString() === 'null';
    }

    private function error(
        AllowedOperationGroup $group,
        string $problem,
        Expr $node,
        string $identifier = self::IDENTIFIER,
    ): IdentifierRuleError {
        return RuleErrorBuilder::message("{$group->describeCallers()} の中の呼び出し: {$problem}。")
            ->identifier($identifier)
            ->line($node->getStartLine())
            ->nonIgnorable()
            ->build();
    }
}
