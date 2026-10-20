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
        Schema::create('record_references', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('record_module_id');
            $table->bigInteger('record_id');
            $table->bigInteger('record_field_id');
            $table->bigInteger('referenced_record_module_id')->nullable();
            $table->bigInteger('referenced_record_id')->nullable();
            $table->foreignId('user_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['record_id', 'record_field_id']);
            $table->foreign(['record_module_id', 'record_id'])->references(['record_module_id', 'id'])->on('records')->cascadeOnDelete();
            $table->foreign(['record_module_id', 'record_field_id'])->references(['record_module_id', 'id'])->on('record_fields')->restrictOnDelete();
            $table->foreign(['referenced_record_module_id', 'referenced_record_id'])->references(['record_module_id', 'id'])->on('records')->restrictOnDelete();
            $table->foreign(['record_field_id', 'referenced_record_module_id'])->references(['id', 'referenced_record_module_id'])->on('record_fields')->restrictOnDelete();
            $table->index(['record_module_id', 'record_id']);
            $table->index(['record_module_id', 'record_field_id']);
            $table->index(['referenced_record_id', 'referenced_record_module_id']);
            $table->index(['record_field_id', 'referenced_record_module_id']);
        });

        DB::statement('ALTER TABLE record_references ADD CONSTRAINT record_references_referenced_record_id_user_id_department_id_referenced_record_module_id_check CHECK (num_nonnulls(referenced_record_id, user_id, department_id) = 1 AND (referenced_record_id IS NULL) = (referenced_record_module_id IS NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('record_references');
    }
};
