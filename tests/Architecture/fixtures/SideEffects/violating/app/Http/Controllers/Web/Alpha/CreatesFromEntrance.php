<?php

namespace App\Http\Controllers\Web\Alpha;

use App\Actions\Alpha\SendReport;
use App\Models\Alpha\AlphaItem;

/**
 * 入口からモデルの静的呼び出しで作成・ジョブの投入
 */
final class CreatesFromEntrance
{
    public function store(): AlphaItem
    {
        return AlphaItem::create(['name' => 'x']);
    }

    public function report(): void
    {
        SendReport::dispatch();
        dispatch(new SendReport);
    }
}
