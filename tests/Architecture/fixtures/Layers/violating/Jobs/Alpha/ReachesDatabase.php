<?php

namespace App\Jobs\Alpha;

use Illuminate\Support\Facades\DB;

/**
 * ジョブ → DB は禁止
 */
final class ReachesDatabase
{
    public function usesDB(DB $dB): DB
    {
        return $dB;
    }
}
