<?php

namespace App\Actions\Ai\ToolCalls;

use Closure;
use ReflectionClass;

/**
 * 判定の関数の名前空間のリフレクションと Closure の束ね替えは止めない(厳しい組は道具の名前空間だけ = 機械検査 (b))
 */
final class ReflectsInJudge
{
    public function shortName(object $value): string
    {
        return (new ReflectionClass($value))->getShortName();
    }

    public function bound(Closure $closure, object $value): ?Closure
    {
        return Closure::bind($closure, $value);
    }
}
