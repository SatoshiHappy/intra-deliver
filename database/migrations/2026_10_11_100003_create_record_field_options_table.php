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
        Schema::create('record_field_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_field_id')->constrained()->cascadeOnDelete();
            $table->string('code', 100);
            $table->string('name', 1000);
            $table->bigInteger('sort_key')->default(0);
            $table->timestampsTz();

            $table->unique(['record_field_id', 'code']);
        });

        DB::statement("ALTER TABLE record_field_options ADD CONSTRAINT record_field_options_code_check CHECK (code ~ '^[a-z0-9_]{1,50}$')");
        DB::statement("ALTER TABLE record_field_options ADD CONSTRAINT record_field_options_name_check CHECK (name <> '')");
        DB::statement('ALTER TABLE record_field_options ADD CONSTRAINT record_field_options_sort_key_check CHECK (sort_key BETWEEN -999999999999999 AND 999999999999999)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('record_field_options');
    }
};
