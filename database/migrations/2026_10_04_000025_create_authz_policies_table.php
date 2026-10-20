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
        Schema::create('authz_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('authz_resource_group_id')->constrained()->restrictOnDelete();
            $table->foreignId('authz_subject_group_id')->index()->constrained()->restrictOnDelete();
            $table->string('action', 100);
            $table->string('effect', 20);
            $table->timestampsTz();

            $table->unique(['authz_resource_group_id', 'authz_subject_group_id', 'action']);
        });

        DB::statement("ALTER TABLE authz_policies ADD CONSTRAINT authz_policies_effect_check CHECK (effect IN ('permit', 'deny'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('authz_policies');
    }
};
