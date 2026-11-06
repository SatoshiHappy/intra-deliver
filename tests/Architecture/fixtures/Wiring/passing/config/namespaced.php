<?php

namespace Config;

use App\Models\Alpha\AlphaItem;

return [
    'model' => AlphaItem::class,
    'padded' => ['01' => AlphaItem::class, '-0' => AlphaItem::class, ' 1' => AlphaItem::class],
];
