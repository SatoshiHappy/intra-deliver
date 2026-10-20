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
        Schema::create('company_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_set_id')->constrained()->restrictOnDelete();
            $table->string('code', 100);
            $table->integer('rank');
            $table->bigInteger('sort_key')->default(0);
            $table->timestampsTz();

            $table->unique(['department_set_id', 'code']);
            $table->unique(['department_set_id', 'id']);
        });

        DB::statement("ALTER TABLE company_posts ADD CONSTRAINT company_posts_code_check CHECK (code <> '')");
        DB::statement('ALTER TABLE company_posts ADD CONSTRAINT company_posts_rank_check CHECK (rank BETWEEN -99999 AND 99999)');
        DB::statement('ALTER TABLE company_posts ADD CONSTRAINT company_posts_sort_key_check CHECK (sort_key BETWEEN -999999999999999 AND 999999999999999)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_posts');
    }
};
