<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * コメントの宛先(コメントの部品。docs の 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.5 節)。
 * 一意制約が外部キー record_comment_id の索引を兼ねる(設計/共通マスタ/共通の規則.md の 9 節)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('record_comment_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->index()->constrained()->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['record_comment_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('record_comment_mentions');
    }
};
