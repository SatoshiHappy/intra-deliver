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
        Schema::create('record_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_module_id')->constrained()->cascadeOnDelete();
            $table->string('code', 100);
            $table->string('name', 1000);
            $table->string('type', 20);
            $table->boolean('is_required')->default(false);
            $table->boolean('is_multiline')->default(false);
            $table->integer('max_length')->nullable();
            $table->jsonb('default_value')->nullable();
            $table->foreignId('referenced_record_module_id')->nullable()->index()->constrained('record_modules')->restrictOnDelete();
            $table->bigInteger('child_record_module_id')->nullable();
            $table->boolean('is_listed')->default(false);
            $table->boolean('is_searchable')->default(false);
            $table->boolean('is_title')->default(false);
            $table->bigInteger('sort_key')->default(0);
            $table->timestampsTz();

            $table->unique(['record_module_id', 'code']);
            $table->unique(['record_module_id', 'id']);
            $table->unique(['id', 'referenced_record_module_id']);
            $table->unique('child_record_module_id');
            $table->foreign(['child_record_module_id', 'record_module_id'])->references(['id', 'parent_record_module_id'])->on('record_modules')->restrictOnDelete();
            $table->index(['child_record_module_id', 'record_module_id']);
        });

        DB::statement("ALTER TABLE record_fields ADD CONSTRAINT record_fields_code_check CHECK (code ~ '^[a-z][a-z0-9_]{0,49}$')");
        DB::statement("ALTER TABLE record_fields ADD CONSTRAINT record_fields_name_check CHECK (name <> '')");
        DB::statement("ALTER TABLE record_fields ADD CONSTRAINT record_fields_type_check CHECK (type IN ('string', 'integer', 'decimal', 'date', 'datetime', 'boolean', 'choice', 'user', 'department', 'record', 'details'))");
        DB::statement('ALTER TABLE record_fields ADD CONSTRAINT record_fields_sort_key_check CHECK (sort_key BETWEEN -999999999999999 AND 999999999999999)');
        DB::statement("ALTER TABLE record_fields ADD CONSTRAINT record_fields_type_referenced_record_module_id_check CHECK ((type = 'record') = (referenced_record_module_id IS NOT NULL))");
        DB::statement("ALTER TABLE record_fields ADD CONSTRAINT record_fields_type_child_record_module_id_check CHECK ((type = 'details') = (child_record_module_id IS NOT NULL))");
        DB::statement("ALTER TABLE record_fields ADD CONSTRAINT record_fields_type_is_multiline_max_length_check CHECK ((type = 'string' OR (NOT is_multiline AND max_length IS NULL)) AND (max_length IS NULL OR max_length BETWEEN 1 AND CASE WHEN is_multiline THEN 4000 ELSE 1000 END))");
        DB::statement("ALTER TABLE record_fields ADD CONSTRAINT record_fields_type_is_required_check CHECK (type <> 'boolean' OR NOT is_required)");
        DB::statement("ALTER TABLE record_fields ADD CONSTRAINT record_fields_type_is_listed_is_searchable_check CHECK (type <> 'details' OR NOT (is_listed OR is_searchable))");
        DB::statement("ALTER TABLE record_fields ADD CONSTRAINT record_fields_type_is_multiline_is_title_check CHECK ((type = 'string' AND NOT is_multiline) OR NOT is_title)");
        DB::statement("ALTER TABLE record_fields ADD CONSTRAINT record_fields_default_value_type_check CHECK (default_value IS NULL OR CASE type WHEN 'integer' THEN default_value::text ~ '^-?[0-9]{1,15}$' WHEN 'boolean' THEN jsonb_typeof(default_value) = 'boolean' WHEN 'string' THEN jsonb_typeof(default_value) = 'string' WHEN 'decimal' THEN jsonb_typeof(default_value) = 'string' WHEN 'date' THEN jsonb_typeof(default_value) = 'string' WHEN 'datetime' THEN jsonb_typeof(default_value) = 'string' WHEN 'choice' THEN jsonb_typeof(default_value) = 'string' ELSE false END)");
        DB::statement('CREATE UNIQUE INDEX record_fields_record_module_id_unique ON record_fields (record_module_id) WHERE is_title');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('record_fields');
    }
};
