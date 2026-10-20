<?php

namespace App\Actions\Alpha;

/**
 * 業務処理の層の抽象クラス(同じ層の継承は可)
 */
abstract class AlphaBaseAction
{
    abstract public function handle(): void;
}
