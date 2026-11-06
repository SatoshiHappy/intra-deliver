<?php

$models = ['model' => App\Models\Alpha\AlphaItem::class];

return ['count' => $models['model']::query()->count()];
