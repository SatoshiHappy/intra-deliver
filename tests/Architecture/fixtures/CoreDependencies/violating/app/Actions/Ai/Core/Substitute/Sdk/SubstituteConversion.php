<?php

namespace App\Actions\Ai\Core\Substitute\Sdk;

use App\Models\Ai\AiRunStep;

/**
 * 代わりの版の変換の 1 か所が Eloquent のモデルに依存するのは禁止
 */
final class SubstituteConversion
{
    public function __construct(public AiRunStep $step) {}
}
