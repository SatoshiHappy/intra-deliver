<?php

use App\Models\Alpha\AlphaItem;
use Illuminate\Support\Facades\Route;

Route::get('/', fn (): int => AlphaItem::query()->count());
