<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('authz_subject_category_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('authz_subject_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('locale_id')->index()->constrained()->restrictOnDelete();
            $table->string('display_name', 1000);
            $table->timestampsTz();

            $table->unique(['authz_subject_category_id', 'locale_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('authz_subject_category_translations');
    }
};
