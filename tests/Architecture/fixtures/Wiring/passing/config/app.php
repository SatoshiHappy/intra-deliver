<?php

use App\Support\SupportValue;
use Illuminate\Support\Str;

return [
    'name' => Str::slug('intra deliver'),
    'value' => SupportValue::class,
    'timezone' => DateTimeZone::UTC,
];
