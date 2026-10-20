<?php

use App\Models\Alpha\AlphaItem;
use Illuminate\Database\Migrations\Migration;

/**
 * サブディレクトリの database/migrations → App\ も禁止(外部だけ)
 */
return new class extends Migration
{
    public function up(): void {}

    public function usesAlphaItem(AlphaItem $alphaItem): AlphaItem
    {
        return $alphaItem;
    }
};
