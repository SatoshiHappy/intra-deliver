<?php

namespace App\Queries\Workflow;

final class UsesQualified
{
    public function run(): string
    {
        return \App\Queries\Master\FindThing::class;
    }
}
