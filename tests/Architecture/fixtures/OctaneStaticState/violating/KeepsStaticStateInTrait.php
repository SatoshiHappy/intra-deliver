<?php

namespace App\Support\OctaneFixture;

trait KeepsStaticStateInTrait
{
    /** @var list<string> */
    public static array $items = [];
}
