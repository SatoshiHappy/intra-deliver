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
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_set_id')->constrained()->restrictOnDelete();
            $table->string('code', 100);
            $table->boolean('is_root')->default(false);
            $table->bigInteger('sort_key')->default(0);
            $table->timestampsTz();

            $table->unique(['department_set_id', 'code']);
            $table->unique(['department_set_id', 'id']);
        });

        DB::statement("ALTER TABLE departments ADD CONSTRAINT departments_code_check CHECK (code <> '')");
        DB::statement('ALTER TABLE departments ADD CONSTRAINT departments_sort_key_check CHECK (sort_key BETWEEN -999999999999999 AND 999999999999999)');
        DB::statement('CREATE UNIQUE INDEX departments_department_set_id_unique ON departments (department_set_id) WHERE is_root');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
