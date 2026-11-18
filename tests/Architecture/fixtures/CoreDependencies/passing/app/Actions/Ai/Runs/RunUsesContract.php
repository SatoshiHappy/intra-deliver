<?php

namespace App\Actions\Ai\Runs;

use App\Actions\Ai\Core\AllowedContract;

/**
 * 繋ぎ(Ai モジュール)が中核の約束を参照するのは許す
 */
final class RunUsesContract
{
    public function __construct(public AllowedContract $contract) {}
}
