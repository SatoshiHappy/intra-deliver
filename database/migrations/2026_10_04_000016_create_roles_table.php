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
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('name', 1000);
            $table->string('category', 1000)->nullable();
            $table->string('notes', 4000)->nullable();
            $table->bigInteger('sort_key')->default(0);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_code_check CHECK (code <> '')");
        DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_name_check CHECK (name ~ '^[A-Za-z0-9_@.+!-]+$')");
        DB::statement('ALTER TABLE roles ADD CONSTRAINT roles_sort_key_check CHECK (sort_key BETWEEN -999999999999999 AND 999999999999999)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
