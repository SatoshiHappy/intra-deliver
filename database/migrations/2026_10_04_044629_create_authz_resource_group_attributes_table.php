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
        Schema::create('authz_resource_group_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('authz_resource_group_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->string('value', 4000);
            $table->timestampsTz();

            $table->unique(['authz_resource_group_id', 'key']);
        });

        DB::statement("ALTER TABLE authz_resource_group_attributes ADD CONSTRAINT authz_resource_group_attributes_key_check CHECK (key <> '')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('authz_resource_group_attributes');
    }
};
