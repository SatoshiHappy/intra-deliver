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
        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->foreignId('parent_id')->nullable()->index()->constrained('menus')->restrictOnDelete();
            $table->bigInteger('sort_number')->default(0);
            $table->string('type', 20);
            $table->string('url', 1000)->nullable();
            $table->string('image_path', 1000)->nullable();
            $table->foreignId('original_menu_id')->nullable()->index()->constrained('menus')->restrictOnDelete();
            $table->string('method', 20)->default('get');
            $table->boolean('is_iframe_used')->default(false);
            $table->boolean('is_popup_used')->default(false);
            $table->string('icon_16', 100)->nullable();
            $table->string('icon_32', 100)->nullable();
            $table->string('icon_48', 100)->nullable();
            $table->string('notes', 4000)->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE menus ADD CONSTRAINT menus_code_check CHECK (code <> '')");
        DB::statement('ALTER TABLE menus ADD CONSTRAINT menus_parent_id_id_check CHECK (parent_id <> id)');
        DB::statement('ALTER TABLE menus ADD CONSTRAINT menus_original_menu_id_id_check CHECK (original_menu_id <> id)');
        DB::statement('ALTER TABLE menus ADD CONSTRAINT menus_sort_number_check CHECK (sort_number BETWEEN -999999999999999 AND 999999999999999)');
        DB::statement("ALTER TABLE menus ADD CONSTRAINT menus_type_check CHECK (type IN ('item', 'folder', 'external'))");
        DB::statement("ALTER TABLE menus ADD CONSTRAINT menus_method_check CHECK (method IN ('get', 'post'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('menus');
    }
};
