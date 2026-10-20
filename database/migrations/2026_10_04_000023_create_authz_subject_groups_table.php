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
        Schema::create('authz_subject_groups', function (Blueprint $table) {
            $table->id();
            $table->string('combination', 20);
            $table->bigInteger('sort_key')->default(0);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE authz_subject_groups ADD CONSTRAINT authz_subject_groups_combination_check CHECK (combination IN ('all', 'any'))");
        DB::statement('ALTER TABLE authz_subject_groups ADD CONSTRAINT authz_subject_groups_sort_key_check CHECK (sort_key BETWEEN -999999999999999 AND 999999999999999)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('authz_subject_groups');
    }
};
