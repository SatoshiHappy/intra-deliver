<?php

namespace App\Actions\Ai\Core\Ported;

use App\Actions\Ai\Core\Substitute\SubstituteHelper;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Exceptions\AiException;

/**
 * 移した版がファサード・ほかの版・変換の 1 か所の外での例外の型に依存するのは禁止
 */
final class PortedHelper
{
    public function __construct(public SubstituteHelper $other) {}

    public function count(): int
    {
        return DB::table('ai_runs')->count();
    }

    public function failed(): AiException
    {
        return new AiException('x');
    }
}
