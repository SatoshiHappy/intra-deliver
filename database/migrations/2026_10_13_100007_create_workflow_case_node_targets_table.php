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
        Schema::create('workflow_case_node_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_case_node_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->index()->constrained()->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['workflow_case_node_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_case_node_targets');
    }
};
