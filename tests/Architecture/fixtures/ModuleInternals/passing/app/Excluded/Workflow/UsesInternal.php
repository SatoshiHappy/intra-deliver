<?php

namespace App\Actions\Workflow;

use App\Actions\Master\DoThing;

final class UsesInternal
{
    public function run(): string
    {
        return DoThing::class;
    }
}
