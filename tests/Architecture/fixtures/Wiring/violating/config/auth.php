<?php

return [
    'count' => App\Models\Alpha\AlphaItem::query()->count(),
    'connection' => Illuminate\Support\Facades\DB::class,
    'job' => App\Jobs\Alpha\AlphaJob::class,
    'created' => App\Models\Alpha\AlphaItem::CREATED_AT,
    'resolved' => app(App\Models\Alpha\AlphaItem::class),
    'queried' => (App\Models\Alpha\AlphaItem::class)::query(),
    'callable' => [App\Models\Alpha\AlphaItem::class, 'query'],
    'indexed' => [0 => App\Models\Alpha\AlphaItem::class, 1 => 'query'],
    'spread' => app(...['abstract' => App\Models\Alpha\AlphaItem::class]),
    'argument' => array_keys(['model' => App\Models\Alpha\AlphaItem::class]),
    'numeric' => ['0' => App\Models\Alpha\AlphaItem::class, '1' => 'query'],
    'listed' => [['model' => App\Models\Alpha\AlphaItem::class]],
    'negative' => ['-1' => App\Models\Alpha\AlphaItem::class],
];
