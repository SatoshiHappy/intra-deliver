<?php

namespace App\Actions\Workflow;

use App\Actions\Master\DoThing;

final class UsesTwice
{
    public function run(): string
    {
        return DoThing::class.\App\Actions\Master\DoThing::class;
    }
}
