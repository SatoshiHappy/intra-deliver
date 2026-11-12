<?php

namespace Tests\Architecture\Engine\PhpStan;

use PhpParser\Node\Arg;

/**
 * 式が指す操作 1 つ(汎用。{@see OperationReferences} が呼び出し・callable の配列・callable の文字列から作り、{@see AllowedOperationsRule} が判定する)。
 */
final readonly class OperationReference
{
    /**
     * @param  list<string>|null  $classes  受け手のクラス(和の型は各クラス。null = 型が決まらない)
     * @param  string|null  $method  操作の名前(null = 字句でない)
     * @param  list<Arg>|null  $arguments  呼び出しの引数(null = callable として指しただけで、渡す引数を決められない)
     */
    public function __construct(public ?array $classes, public ?string $method, public ?array $arguments) {}
}
