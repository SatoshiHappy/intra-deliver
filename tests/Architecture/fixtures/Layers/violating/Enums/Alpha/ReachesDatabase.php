<?php

namespace App\Enums\Alpha;

use Illuminate\Support\Facades\DB;

/**
 * 列挙 → DB は禁止
 */
final class ReachesDatabase
{
    public function usesDB(DB $dB): DB
    {
        return $dB;
    }
}
