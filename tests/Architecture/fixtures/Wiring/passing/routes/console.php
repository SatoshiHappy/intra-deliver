<?php

use App\Console\Commands\Alpha\PruneAlpha;
use Illuminate\Support\Facades\Schedule;

Schedule::command(PruneAlpha::class)->daily();
