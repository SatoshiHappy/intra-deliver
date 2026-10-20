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
        Schema::create('department_inclusions', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('department_set_id');
            $table->bigInteger('parent_department_id');
            $table->bigInteger('department_id');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->bigInteger('sort_key')->default(0);
            $table->timestampsTz();

            $table->foreign(['department_set_id', 'parent_department_id'])->references(['department_set_id', 'id'])->on('departments')->restrictOnDelete();
            $table->foreign(['department_set_id', 'department_id'])->references(['department_set_id', 'id'])->on('departments')->restrictOnDelete();
            $table->index(['department_set_id', 'parent_department_id']);
            $table->index(['department_set_id', 'department_id']);
        });

        DB::statement('ALTER TABLE department_inclusions ADD CONSTRAINT department_inclusions_parent_department_id_department_id_check CHECK (parent_department_id <> department_id)');
        DB::statement('ALTER TABLE department_inclusions ADD CONSTRAINT department_inclusions_start_date_end_date_check CHECK (isfinite(start_date) AND (end_date IS NULL OR (isfinite(end_date) AND start_date < end_date)))');
        DB::statement('ALTER TABLE department_inclusions ADD CONSTRAINT department_inclusions_sort_key_check CHECK (sort_key BETWEEN -999999999999999 AND 999999999999999)');
        DB::statement("ALTER TABLE department_inclusions ADD CONSTRAINT department_inclusions_parent_department_id_department_id_start_date_end_date_excl EXCLUDE USING gist (parent_department_id WITH =, department_id WITH =, daterange(start_date, end_date, '[)') WITH &&)");
        DB::statement("ALTER TABLE department_inclusions ADD CONSTRAINT department_inclusions_department_id_start_date_end_date_excl EXCLUDE USING gist (department_id WITH =, daterange(start_date, end_date, '[)') WITH &&) WHERE (NOT is_deleted)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('department_inclusions');
    }
};
