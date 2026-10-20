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
        Schema::create('workflow_routes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('name', 1000);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE workflow_routes ADD CONSTRAINT workflow_routes_code_check CHECK (code ~ '^[a-z][a-z0-9_]{0,49}$')");
        DB::statement("ALTER TABLE workflow_routes ADD CONSTRAINT workflow_routes_name_check CHECK (name <> '')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_routes');
    }
};
