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
        Schema::create('department_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 100);
            $table->boolean('is_default')->default(false);
            $table->bigInteger('sort_key')->default(0);
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
        });

        DB::statement("ALTER TABLE department_sets ADD CONSTRAINT department_sets_code_check CHECK (code <> '')");
        DB::statement('ALTER TABLE department_sets ADD CONSTRAINT department_sets_sort_key_check CHECK (sort_key BETWEEN -999999999999999 AND 999999999999999)');
        DB::statement('CREATE UNIQUE INDEX department_sets_company_id_unique ON department_sets (company_id) WHERE is_default');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('department_sets');
    }
};
