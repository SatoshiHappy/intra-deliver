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
        Schema::create('record_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_module_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->bigInteger('target_record_field_id');
            $table->jsonb('condition')->nullable();
            $table->string('operator', 20)->nullable();
            $table->bigInteger('compared_record_field_id')->nullable();
            $table->string('message', 1000)->nullable();
            $table->timestampsTz();

            $table->foreign(['record_module_id', 'target_record_field_id'])->references(['record_module_id', 'id'])->on('record_fields')->restrictOnDelete();
            $table->foreign(['record_module_id', 'compared_record_field_id'])->references(['record_module_id', 'id'])->on('record_fields')->restrictOnDelete();
            $table->index(['record_module_id', 'target_record_field_id']);
            $table->index(['record_module_id', 'compared_record_field_id']);
        });

        DB::statement("ALTER TABLE record_rules ADD CONSTRAINT record_rules_kind_check CHECK (kind IN ('required_when', 'compare'))");
        DB::statement("ALTER TABLE record_rules ADD CONSTRAINT record_rules_operator_check CHECK (operator IN ('lt', 'le', 'eq', 'ne', 'ge', 'gt'))");
        DB::statement("ALTER TABLE record_rules ADD CONSTRAINT record_rules_kind_condition_operator_compared_record_field_id_check CHECK ((kind = 'required_when' AND condition IS NOT NULL AND operator IS NULL AND compared_record_field_id IS NULL) OR (kind = 'compare' AND condition IS NULL AND operator IS NOT NULL AND compared_record_field_id IS NOT NULL))");
        DB::statement("ALTER TABLE record_rules ADD CONSTRAINT record_rules_condition_check CHECK (jsonb_typeof(condition) = 'object')");
        DB::statement('ALTER TABLE record_rules ADD CONSTRAINT record_rules_target_record_field_id_compared_record_field_id_check CHECK (target_record_field_id <> compared_record_field_id)');
        DB::statement("ALTER TABLE record_rules ADD CONSTRAINT record_rules_message_check CHECK (message <> '')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('record_rules');
    }
};
