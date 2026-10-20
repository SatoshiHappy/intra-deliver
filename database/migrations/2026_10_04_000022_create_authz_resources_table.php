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
        Schema::create('authz_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('authz_resource_group_id')->unique()->constrained()->restrictOnDelete();
            $table->string('resource_type', 100);
            $table->string('uri', 1000)->unique();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('authz_resources');
    }
};
