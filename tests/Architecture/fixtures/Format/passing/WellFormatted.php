<?php

namespace Fixture\Format;

use Illuminate\Support\Str;

final class WellFormatted
{
    public function name(): string
    {
        return Str::lower('FORMAT');
    }
}
