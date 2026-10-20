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
        Schema::create('company_post_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_post_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE company_post_terms ADD CONSTRAINT company_post_terms_start_date_end_date_check CHECK (isfinite(start_date) AND (end_date IS NULL OR (isfinite(end_date) AND start_date < end_date)))');
        DB::statement("ALTER TABLE company_post_terms ADD CONSTRAINT company_post_terms_company_post_id_start_date_end_date_excl EXCLUDE USING gist (company_post_id WITH =, daterange(start_date, end_date, '[)') WITH &&)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_post_terms');
    }
};
