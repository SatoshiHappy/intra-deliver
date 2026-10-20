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
        Schema::create('menu_arguments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('value', 4000);
            $table->timestampsTz();

            $table->unique(['menu_id', 'name']);
        });

        DB::statement("ALTER TABLE menu_arguments ADD CONSTRAINT menu_arguments_name_check CHECK (name <> '')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('menu_arguments');
    }
};
