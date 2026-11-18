<?php

namespace App\Actions\Ai\Core;

use App\Models\Ai\AiRun;

/**
 * 約束が Eloquent のモデルに依存するのは禁止(13.1 の約束の行に無い)
 */
final class ContractUsesModel
{
    public function __construct(public AiRun $run) {}
}
