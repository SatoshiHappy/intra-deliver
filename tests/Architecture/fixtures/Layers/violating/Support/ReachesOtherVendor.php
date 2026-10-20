<?php

namespace App\Support;

use Acme\Vendor\Widget;

/**
 * composer.lock の packages に無い名前空間は外部でない
 */
final class ReachesOtherVendor
{
    public function usesWidget(Widget $widget): Widget
    {
        return $widget;
    }
}
