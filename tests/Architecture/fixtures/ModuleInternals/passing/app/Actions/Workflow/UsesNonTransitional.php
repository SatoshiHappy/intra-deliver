<?php

namespace App\Actions\Workflow;

use App\Actions\Records\WriteThing;

final class UsesNonTransitional
{
    public function run(): string
    {
        return WriteThing::class;
    }
}
