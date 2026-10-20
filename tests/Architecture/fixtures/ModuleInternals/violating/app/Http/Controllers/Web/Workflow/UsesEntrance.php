<?php

namespace App\Http\Controllers\Web\Workflow;

use App\Http\Requests\Records\ReadsThing;

final class UsesEntrance
{
    public function run(): string
    {
        return ReadsThing::class;
    }
}
