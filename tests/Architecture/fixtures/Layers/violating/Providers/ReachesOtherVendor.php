<?php

namespace App\Providers;

use Acme\Vendor\Widget;

/**
 * 起動 → 外部でない名前空間は禁止
 */
final class ReachesOtherVendor
{
    public function usesWidget(Widget $widget): Widget
    {
        return $widget;
    }
}
