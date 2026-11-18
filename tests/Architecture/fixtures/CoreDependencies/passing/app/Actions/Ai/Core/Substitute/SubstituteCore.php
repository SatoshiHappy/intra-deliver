<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Core\SendKind;
use App\Actions\Ai\Core\Substitute\Sdk\SubstituteConversion;
use Illuminate\Support\Str;

/**
 * 代わりの版が依れる物(約束・その版の中・illuminate/support)
 */
final class SubstituteCore
{
    public function __construct(public SubstituteConversion $conversion) {}

    public function id(SendKind $kind): string
    {
        return $kind->value.Str::uuid()->toString();
    }
}
