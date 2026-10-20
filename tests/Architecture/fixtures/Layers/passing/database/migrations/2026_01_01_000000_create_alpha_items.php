<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * database/migrations → 外部
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alpha_items', function (Blueprint $table): void {
            $table->id();
        });
    }
};
