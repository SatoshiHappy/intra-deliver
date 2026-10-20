<?php

use App\Http\Controllers\Web\Alpha\AlphaController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AlphaController::class, 'show']);
