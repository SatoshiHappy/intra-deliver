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
        Schema::create('company_post_term_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_post_term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('locale_id')->index()->constrained()->restrictOnDelete();
            $table->string('name', 1000);
            $table->string('notes', 4000)->nullable();
            $table->timestampsTz();

            $table->unique(['company_post_term_id', 'locale_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_post_term_translations');
    }
};
