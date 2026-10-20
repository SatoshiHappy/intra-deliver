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
        Schema::create('authz_subject_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('authz_subject_group_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->foreignId('company_post_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->foreignId('role_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->string('operator', 20)->default('eq');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE authz_subject_conditions ADD CONSTRAINT authz_subject_conditions_operator_check CHECK (operator IN ('lt', 'le', 'eq', 'ge', 'gt'))");
        DB::statement('ALTER TABLE authz_subject_conditions ADD CONSTRAINT authz_subject_conditions_user_id_department_id_company_post_id_role_id_check CHECK (num_nonnulls(user_id, department_id, company_post_id, role_id) = 1)');
        DB::statement("ALTER TABLE authz_subject_conditions ADD CONSTRAINT authz_subject_conditions_operator_department_id_company_post_id_check CHECK (operator = 'eq' OR department_id IS NOT NULL OR company_post_id IS NOT NULL)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('authz_subject_conditions');
    }
};
