<?php

namespace App\Actions\Workflow;

use App\Actions\Master\PrivateThing;

/**
 * 木の側から、リンクの先だけに在る内部のクラスへの依存
 */
final class UsesPrivateInternal
{
    public function name(): string
    {
        return PrivateThing::class;
    }
}
