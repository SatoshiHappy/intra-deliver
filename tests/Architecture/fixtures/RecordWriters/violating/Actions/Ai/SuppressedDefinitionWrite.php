<?php

namespace App\Actions\Ai;

use App\Actions\Ai\Api\SaveAgentDefinition;

/**
 * 抑制の注釈を付けても消せない(nonIgnorable)。Ai の内部も定義を書く口の呼び手の一覧の外
 */
final class SuppressedDefinitionWrite
{
    // @phpstan-ignore phpat.definitionWriteDependencies
    public function save(SaveAgentDefinition $save): SaveAgentDefinition
    {
        return $save;
    }
}
