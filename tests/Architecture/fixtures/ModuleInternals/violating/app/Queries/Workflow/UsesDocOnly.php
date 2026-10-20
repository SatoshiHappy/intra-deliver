<?php

namespace App\Queries\Workflow;

final class UsesDocOnly
{
    /**
     * @param  list<\App\Queries\Master\FindThing>  $things
     */
    public function run(array $things): int
    {
        return count($things);
    }
}
