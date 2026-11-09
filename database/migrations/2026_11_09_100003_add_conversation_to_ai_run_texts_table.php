<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 送った文と応答の原文に、会話の型の実行の分を足す(docs の 設計/AI/実行の記録.md 2.2、途中の文 = 設計/AI/エージェントの実行/途中の表示と上限.md 9.6)。
 * 会話の型の実行はシステムの文を持たず、利用者の文は文の実行だけが持つので、2 つの文を NULL 可にする。文章・文字起こしの行が 2 つの文を持つことは、
 * 依頼の口とテストで守る(種類の型は ai_runs にあり、表をまたぐので検査制約にしない)。
 * 途中の文は、その実行の回の答えの文をステップごとに区切って順に並べた配列(要素 = ステップの番号と文)。原文と同じ期限で行ごと消える。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_run_texts', function (Blueprint $table) {
            $table->text('system_text')->nullable()->change();
            $table->text('user_text')->nullable()->change();
            $table->jsonb('partial_text')->nullable();
        });
        DB::statement('ALTER TABLE ai_run_texts ADD CONSTRAINT ai_run_texts_partial_text_check'
            ." CHECK (jsonb_typeof(partial_text) = 'array')");
    }

    /**
     * Reverse the migrations。文の無い行か途中の文のある行(会話の型の実行の原文)があると戻せない(行を消すことになる)。
     */
    public function down(): void
    {
        $conversation = DB::table('ai_run_texts')
            ->where(fn ($query) => $query->whereNull('system_text')
                ->orWhereNull('user_text')
                ->orWhereNotNull('partial_text'))
            ->exists();

        if ($conversation) {
            throw new RuntimeException('会話の型の実行の原文の行が残っているので戻せない(2 つの文を NOT NULL に戻すと、その行を消すことになる)');
        }

        DB::statement('ALTER TABLE ai_run_texts DROP CONSTRAINT ai_run_texts_partial_text_check');
        Schema::table('ai_run_texts', function (Blueprint $table) {
            $table->dropColumn('partial_text');
            $table->text('system_text')->nullable(false)->change();
            $table->text('user_text')->nullable(false)->change();
        });
    }
};
