<?php

namespace Database\Seeders;

use App\Actions\Master\DoThing;

final class ThingSeeder
{
    public function run(): string
    {
        return DoThing::class;
    }
}
