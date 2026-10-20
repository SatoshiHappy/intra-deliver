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
        Schema::create('department_memberships', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('department_set_id');
            $table->bigInteger('department_id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->boolean('is_main')->default(false);
            $table->bigInteger('sort_key')->default(0);
            $table->timestampsTz();

            $table->foreign(['department_set_id', 'department_id'])->references(['department_set_id', 'id'])->on('departments')->restrictOnDelete();
            $table->index(['department_set_id', 'department_id']);
            $table->unique(['department_set_id', 'id']);
        });

        DB::statement('ALTER TABLE department_memberships ADD CONSTRAINT department_memberships_start_date_end_date_check CHECK (isfinite(start_date) AND (end_date IS NULL OR (isfinite(end_date) AND start_date < end_date)))');
        DB::statement('ALTER TABLE department_memberships ADD CONSTRAINT department_memberships_sort_key_check CHECK (sort_key BETWEEN -999999999999999 AND 999999999999999)');
        DB::statement("ALTER TABLE department_memberships ADD CONSTRAINT department_memberships_user_id_department_id_start_date_end_date_excl EXCLUDE USING gist (user_id WITH =, department_id WITH =, daterange(start_date, end_date, '[)') WITH &&)");
        DB::statement("ALTER TABLE department_memberships ADD CONSTRAINT department_memberships_user_id_department_set_id_start_date_end_date_excl EXCLUDE USING gist (user_id WITH =, department_set_id WITH =, daterange(start_date, end_date, '[)') WITH &&) WHERE (is_main AND NOT is_deleted)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('department_memberships');
    }
};
