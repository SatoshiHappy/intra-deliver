<?php

use App\Http\Middleware\HandleCommon;
use App\Providers\FixtureProvider;
use Illuminate\Http\Request;

return [
    'middleware' => [HandleCommon::class],
    'providers' => [FixtureProvider::class],
    'resolver' => static fn (Request $request): string => $request->path(),
];
