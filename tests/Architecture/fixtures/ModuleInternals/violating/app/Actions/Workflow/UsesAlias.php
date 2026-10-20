<?php

namespace App\Actions\Workflow;

use App\Actions\Master\DoThing as MasterThing;

final class UsesAlias
{
    public function run(): string
    {
        return MasterThing::class;
    }
}
