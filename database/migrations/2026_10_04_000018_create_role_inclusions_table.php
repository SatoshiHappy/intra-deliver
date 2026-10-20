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
        Schema::create('role_inclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_role_id')->constrained('roles')->restrictOnDelete();
            $table->foreignId('child_role_id')->index()->constrained('roles')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['parent_role_id', 'child_role_id']);
        });

        DB::statement('ALTER TABLE role_inclusions ADD CONSTRAINT role_inclusions_parent_role_id_child_role_id_check CHECK (parent_role_id <> child_role_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_inclusions');
    }
};
