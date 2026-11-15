<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 通知の必須の印の表(1 行 = 種類 1 つが必須。docs の ADR 0045 の決定 1)。
 * 種類の値の一覧は検査制約にせず、書式だけを見る(notices.type と同じ)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notice_required_types', function (Blueprint $table) {
            $table->id();
            $table->string('type', 100)->unique();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE notice_required_types ADD CONSTRAINT notice_required_types_type_check'
            ." CHECK (type ~ '^[a-z][a-z0-9_]*\\.[a-z][a-z0-9_]*$')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notice_required_types');
    }
};
