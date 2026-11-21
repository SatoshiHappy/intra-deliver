<?php

namespace App\Actions\Ai\ToolCalls;

use App\Queries\Records\Api\RecordModuleDirectory;

/**
 * 判定の関数の名前空間が、判定を通す定義の読み取りの代わりに判定しない定義の読み取りを呼ぶのは禁止
 */
final class ReadsUncheckedDefinition
{
    public function __construct(private RecordModuleDirectory $directory) {}

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return $this->directory->definitionUnchecked('tasks');
    }
}
