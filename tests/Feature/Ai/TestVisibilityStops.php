<?php

namespace Tests\Feature\Ai;

use App\Queries\Records\Api\RecordModuleVisibilityStops;

/** テストの中で立てて消せる業務モジュールの表示の止め */
final class TestVisibilityStops implements RecordModuleVisibilityStops
{
    /** @var array<int, string> 業務モジュールの id → 理由 */
    public array $reasons = [];

    public function stopReason(int $recordModuleId): ?string
    {
        return $this->reasons[$recordModuleId] ?? null;
    }
}
