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
        Schema::create('department_membership_posts', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('department_set_id');
            $table->bigInteger('department_membership_id');
            $table->bigInteger('company_post_id');
            $table->timestampsTz();

            $table->foreign(['department_set_id', 'department_membership_id'])->references(['department_set_id', 'id'])->on('department_memberships')->cascadeOnDelete();
            $table->foreign(['department_set_id', 'company_post_id'])->references(['department_set_id', 'id'])->on('company_posts')->restrictOnDelete();
            $table->index(['department_set_id', 'department_membership_id']);
            $table->index(['department_set_id', 'company_post_id']);
            $table->unique(['department_membership_id', 'company_post_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('department_membership_posts');
    }
};
