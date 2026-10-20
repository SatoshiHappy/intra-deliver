<?php

namespace App\Http\Controllers\Web\Alpha;

use Illuminate\Support\Facades\DB;

/**
 * 入口 → DB は禁止
 */
final class ReachesDatabase
{
    public function usesDB(DB $dB): DB
    {
        return $dB;
    }
}
