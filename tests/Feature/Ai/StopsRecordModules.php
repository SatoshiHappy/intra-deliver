<?php

namespace Tests\Feature\Ai;

use App\Queries\Records\Api\RecordModuleVisibilityStops;

/**
 * 業務モジュール 1 つに表示の止めを立てる(止めを持つ業務処理の代わり。テストの中だけ)。
 */
trait StopsRecordModules
{
    private function stopModule(int $recordModuleId): void
    {
        $this->app->instance('test.stops.ai', new class($recordModuleId) implements RecordModuleVisibilityStops
        {
            public function __construct(private int $stopped) {}

            public function stopReason(int $recordModuleId): ?string
            {
                return $recordModuleId === $this->stopped ? '止め' : null;
            }
        });
        $this->app->tag(['test.stops.ai'], RecordModuleVisibilityStops::TAG);
    }
}
