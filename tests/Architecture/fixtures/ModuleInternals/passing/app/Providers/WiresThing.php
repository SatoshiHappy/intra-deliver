<?php

namespace App\Providers;

use App\Actions\Master\DoThing;

final class WiresThing
{
    public function run(): string
    {
        return DoThing::class;
    }
}
