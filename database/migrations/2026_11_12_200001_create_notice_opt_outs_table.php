<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 通知の受け取りの設定の表(1 行 = 利用者 1 人が種類 1 つを受け取らない。docs の ADR 0045 の決定 1。規則 =
 * 設計/ワークフロー/申請の周辺/通知の受け取りの設定/種類と作る規則.md の 2 節)。
 * 種類の値の一覧は検査制約にせず、書式だけを見る(notices.type と同じ)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notice_opt_outs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 100);
            $table->timestampsTz();

            // 外部キー user_id の索引を兼ねる
            $table->unique(['user_id', 'type']);
        });

        DB::statement('ALTER TABLE notice_opt_outs ADD CONSTRAINT notice_opt_outs_type_check'
            ." CHECK (type ~ '^[a-z][a-z0-9_]*\\.[a-z][a-z0-9_]*$')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notice_opt_outs');
    }
};
