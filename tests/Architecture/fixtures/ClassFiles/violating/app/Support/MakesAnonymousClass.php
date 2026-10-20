<?php

namespace App\Support;

/**
 * メソッドの中の匿名クラス
 */
final class MakesAnonymousClass
{
    public function make(): object
    {
        return new class {};
    }
}
