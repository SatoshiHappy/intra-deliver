<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Instructions\InstructionBuilder;

/**
 * 代わりの版が Ai の内部(指示の組み立ての口)に依存するのは禁止(受け手を通す)
 */
final class SubstituteHelper
{
    public function __construct(public InstructionBuilder $builder) {}
}
