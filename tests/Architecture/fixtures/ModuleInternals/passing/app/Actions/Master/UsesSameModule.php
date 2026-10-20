<?php

namespace App\Actions\Master;

use App\Http\Requests\Master\ReadsOwn;

final class UsesSameModule
{
    public function run(): string
    {
        return DoThing::class.ReadsOwn::class;
    }
}
