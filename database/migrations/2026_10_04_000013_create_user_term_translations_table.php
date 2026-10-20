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
        Schema::create('user_term_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('locale_id')->index()->constrained()->restrictOnDelete();
            $table->string('name', 1000);
            $table->string('search_name', 1000)->nullable();
            $table->string('country_code', 100)->nullable();
            $table->string('zip_code', 50)->nullable();
            $table->string('address1', 1000)->nullable();
            $table->string('address2', 1000)->nullable();
            $table->string('address3', 1000)->nullable();
            $table->string('telephone_number', 50)->nullable();
            $table->string('extension_number', 50)->nullable();
            $table->string('fax_number', 50)->nullable();
            $table->string('extension_fax_number', 50)->nullable();
            $table->string('mobile_number', 50)->nullable();
            $table->string('email_address1', 256)->nullable();
            $table->string('email_address2', 256)->nullable();
            $table->string('mobile_email_address', 256)->nullable();
            $table->string('url', 1000)->nullable();
            $table->string('notes', 4000)->nullable();
            $table->timestampsTz();

            $table->unique(['user_term_id', 'locale_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_term_translations');
    }
};
