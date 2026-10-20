<?php

namespace App\Queries\Alpha;

/**
 * 読み取りの層の抽象クラス
 */
abstract class AlphaBase
{
    abstract public function write(): void;
}
