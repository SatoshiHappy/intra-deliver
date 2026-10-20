<?php

namespace App\Actions\Workflow;

use App\Actions\Master\Api\PublicThing;

final class UsesApi
{
    public function run(): string
    {
        return PublicThing::class;
    }
}
