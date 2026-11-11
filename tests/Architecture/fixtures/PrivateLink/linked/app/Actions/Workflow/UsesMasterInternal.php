<?php

namespace App\Actions\Workflow;

use App\Actions\Master\DoThing;

/**
 * リンクの先から、移行中のモジュールの内部への依存
 */
final class UsesMasterInternal
{
    public function name(): string
    {
        return DoThing::class;
    }
}
