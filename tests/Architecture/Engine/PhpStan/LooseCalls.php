<?php

namespace Tests\Architecture\Engine\PhpStan;

use Closure;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;

/**
 * 届く操作と引数を決められない呼び出しの形(汎用。{@see AllowedOperationsRule} が厳しい組で使う部品)。
 *
 * 形 = 引数の展開(...$args)を持つ呼び出しと生成、字句でない callable を受ける関数(設定の一覧。call_user_func など)、
 * Closure でない値の呼び出し($callable())。返すのは指摘の文。
 */
final readonly class LooseCalls
{
    /** @var list<string> 小文字の関数名 */
    private array $callableFunctions;

    /**
     * @param  list<string>  $callableFunctions  字句でない callable を受ける関数
     */
    public function __construct(array $callableFunctions)
    {
        $this->callableFunctions = array_map(fn (string $name) => strtolower(ltrim($name, '\\')), $callableFunctions);
    }

    /**
     * @return list<string>
     */
    public function problemsOf(Expr $node, Scope $scope): array
    {
        $problems = [];

        if (self::hasSpread($node)) {
            $problems[] = '引数の展開(...)は書けない(届く操作と鍵の引数を決められない)';
        }

        if ($node instanceof Expr\FuncCall) {
            array_push($problems, ...$this->functionProblems($node, $scope));
        }

        return $problems;
    }

    private static function hasSpread(Expr $node): bool
    {
        if (! ($node instanceof Expr\CallLike) || $node->isFirstClassCallable()) {
            return false;
        }

        return array_filter($node->getArgs(), fn (Arg $arg): bool => $arg->unpack) !== [];
    }

    /**
     * @return list<string>
     */
    private function functionProblems(Expr\FuncCall $node, Scope $scope): array
    {
        if (! $node->name instanceof Name) {
            $isClosure = (new ObjectType(Closure::class))->isSuperTypeOf($scope->getType($node->name))->yes();

            return $isClosure ? [] : ['Closure でない値の呼び出しは書けない(届く操作を決められない)'];
        }

        return in_array(strtolower($node->name->toString()), $this->callableFunctions, true)
            ? ["字句でない callable を受ける関数 {$node->name->toString()} は書けない(届く操作と鍵の引数を決められない)"]
            : [];
    }
}
