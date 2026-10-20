<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('menu_group_category_inclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_group_id')->constrained()->restrictOnDelete();
            $table->foreignId('menu_group_category_id')->index()->constrained()->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['menu_group_id', 'menu_group_category_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('menu_group_category_inclusions');
    }
};
