<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 道具の行の原文に、問うの呼び出しの書く値の引数(相手の名前を呼ばれた時に引き当てた id に直し、参照制限のキーを決めた後の引数)を足す
 * (docs の 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.5: 書く値は確認の内容により、再開の引数の名前から書かない)。確認のカードに
 * 出す確認の内容とは別の列にする(書く値の引数はカードに出さない)。持てるのは確認の内容のある行だけ。前の版の確認の内容の行は値が無いまま
 * 通る(前方互換。書く時の部品はその行を書かずに道具の誤りにする)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_tool_call_texts', function (Blueprint $table) {
            $table->jsonb('written_arguments')->nullable();
        });

        DB::statement('ALTER TABLE ai_tool_call_texts ADD CONSTRAINT ai_tool_call_texts_written_arguments_check'
            ." CHECK (jsonb_typeof(written_arguments) = 'object')");
        DB::statement('ALTER TABLE ai_tool_call_texts ADD CONSTRAINT ai_tool_call_texts_written_arguments_pair_check'
            .' CHECK (written_arguments IS NULL OR confirmation IS NOT NULL)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_tool_call_texts', function (Blueprint $table) {
            $table->dropColumn('written_arguments');
        });
    }
};
