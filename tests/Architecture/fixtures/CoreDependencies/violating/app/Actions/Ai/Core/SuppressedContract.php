<?php

namespace App\Actions\Ai\Core;

use App\Actions\Ai\Instructions\InstructionBuilder;

/**
 * 抑制の注釈を付けても消せない
 */
final class SuppressedContract
{
    /** @phpstan-ignore phpat.coreContractDependencies */
    public function __construct(public InstructionBuilder $builder) {}
}
