<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 通知の表(docs の 設計/ワークフロー/案件の運用/通知.md の 2 節)。対象の 2 列は外部キーを持たない(対象が消えても通知を残し、
 * 移り先は表示の時に解く = 同じ文書の 5 節)。種類の値の一覧は検査制約にせず、書式だけを見る。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 100);
            $table->string('target_type', 100)->nullable();
            $table->bigInteger('target_id')->nullable();
            $table->foreignId('actor_user_id')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->jsonb('params');
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();

            // 自分宛の新しい順(外部キー user_id の索引を兼ねる)
            $table->index(['user_id', 'created_at', 'id']);
        });

        DB::statement('ALTER TABLE notices ADD CONSTRAINT notices_type_check'
            ." CHECK (type ~ '^[a-z][a-z0-9_]*\\.[a-z][a-z0-9_]*$')");
        DB::statement('ALTER TABLE notices ADD CONSTRAINT notices_target_type_check'
            ." CHECK (target_type ~ '^[a-z][a-z0-9-]*$')");
        DB::statement('ALTER TABLE notices ADD CONSTRAINT notices_target_type_target_id_check'
            .' CHECK ((target_type IS NULL) = (target_id IS NULL))');
        DB::statement('ALTER TABLE notices ADD CONSTRAINT notices_params_check'
            ." CHECK (jsonb_typeof(params) = 'object')");
        // 未読の数(述語の列は名前に入れない = docs の 設計/共通マスタ/共通の規則.md の 9 節の形)
        DB::statement('CREATE INDEX notices_user_id_index ON notices (user_id) WHERE read_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notices');
    }
};
