<?php

namespace Tests\Feature\Ai;

use App\Queries\Records\Api\RecordModuleDirectory;

/**
 * 業務モジュールの一覧と表示の止めの読み取りの呼び出しを数える口(テストの中だけ。読み取りは元の口のまま)。
 */
final class CountingModuleDirectory extends RecordModuleDirectory
{
    public int $listCalls = 0;

    /** @var list<string> 止めを読んだ業務モジュール */
    public array $stopCalls = [];

    public function topLevelModules(): array
    {
        $this->listCalls++;

        return parent::topLevelModules();
    }

    public function visibilityStopReason(string $moduleCode): ?string
    {
        $this->stopCalls[] = $moduleCode;

        return parent::visibilityStopReason($moduleCode);
    }
}
