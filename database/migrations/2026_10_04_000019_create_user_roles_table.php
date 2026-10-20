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
        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('role_id')->index()->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE user_roles ADD CONSTRAINT user_roles_start_date_end_date_check CHECK (isfinite(start_date) AND (end_date IS NULL OR (isfinite(end_date) AND start_date < end_date)))');
        DB::statement("ALTER TABLE user_roles ADD CONSTRAINT user_roles_user_id_role_id_start_date_end_date_excl EXCLUDE USING gist (user_id WITH =, role_id WITH =, daterange(start_date, end_date, '[)') WITH &&)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_roles');
    }
};
