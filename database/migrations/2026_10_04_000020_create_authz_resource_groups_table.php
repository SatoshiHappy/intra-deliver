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
        Schema::create('authz_resource_groups', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->foreignId('parent_id')->nullable()->index()->constrained('authz_resource_groups')->restrictOnDelete();
            $table->bigInteger('sort_key')->default(0);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE authz_resource_groups ADD CONSTRAINT authz_resource_groups_code_check CHECK (code <> '')");
        DB::statement('ALTER TABLE authz_resource_groups ADD CONSTRAINT authz_resource_groups_parent_id_id_check CHECK (parent_id <> id)');
        DB::statement('ALTER TABLE authz_resource_groups ADD CONSTRAINT authz_resource_groups_sort_key_check CHECK (sort_key BETWEEN -999999999999999 AND 999999999999999)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('authz_resource_groups');
    }
};
