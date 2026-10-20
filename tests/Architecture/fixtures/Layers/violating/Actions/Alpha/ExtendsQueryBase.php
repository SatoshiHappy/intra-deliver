<?php

namespace App\Actions\Alpha;

use App\Queries\Alpha\AlphaBase;

/**
 * 業務処理が読み取りのクラスを継承するのは禁止(読み取りから書き込みに届く)
 */
final class ExtendsQueryBase extends AlphaBase
{
    public function write(): void {}
}
