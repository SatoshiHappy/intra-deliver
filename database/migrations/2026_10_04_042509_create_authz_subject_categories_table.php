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
        Schema::create('authz_subject_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->bigInteger('sort_key')->default(0);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE authz_subject_categories ADD CONSTRAINT authz_subject_categories_code_check CHECK (code <> '')");
        DB::statement('ALTER TABLE authz_subject_categories ADD CONSTRAINT authz_subject_categories_sort_key_check CHECK (sort_key BETWEEN -999999999999999 AND 999999999999999)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('authz_subject_categories');
    }
};
