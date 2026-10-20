<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * コメント(レコードの部品。docs の 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.4 節)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('record_comments', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('record_module_id');
            $table->bigInteger('record_id');
            $table->foreignId('user_id')->index()->constrained()->restrictOnDelete();
            $table->string('body', 4000)->nullable();
            $table->timestampTz('deleted_at')->nullable();
            $table->foreignId('deleted_by_user_id')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->foreign(['record_module_id', 'record_id'])
                ->references(['record_module_id', 'id'])->on('records')->cascadeOnDelete();
            // 複合外部キーの索引を兼ね、レコードのコメントを新しい順に引く
            $table->index(['record_module_id', 'record_id', 'id']);
        });

        DB::statement("ALTER TABLE record_comments ADD CONSTRAINT record_comments_body_check CHECK (body <> '')");
        DB::statement('ALTER TABLE record_comments'
            .' ADD CONSTRAINT record_comments_deleted_at_body_deleted_by_user_id_check'
            .' CHECK ((deleted_at IS NULL) = (body IS NOT NULL)'
            .' AND (deleted_at IS NULL) = (deleted_by_user_id IS NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('record_comments');
    }
};
