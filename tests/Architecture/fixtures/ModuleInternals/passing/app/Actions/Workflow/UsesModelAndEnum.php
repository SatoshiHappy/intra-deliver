<?php

namespace App\Actions\Workflow;

use App\Enums\Master\Kind;
use App\Models\Master\Thing;

final class UsesModelAndEnum
{
    public function run(): string
    {
        return Thing::class.Kind::class;
    }
}
