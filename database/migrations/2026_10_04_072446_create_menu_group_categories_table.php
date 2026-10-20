<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('menu_group_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->boolean('is_selectable')->default(false);
            $table->boolean('is_editable')->default(false);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE menu_group_categories ADD CONSTRAINT menu_group_categories_code_check CHECK (code <> '')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('menu_group_categories');
    }
};
